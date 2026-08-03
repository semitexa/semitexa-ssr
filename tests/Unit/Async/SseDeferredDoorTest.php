<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseDeferredDoor;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;

/**
 * Direct tests for {@see SseDeferredDoor}, extracted from AsyncResourceSseServer
 * by ep-slay-sse-god-class-2 (tk-sse2-deferred-door).
 *
 * The behaviour under test is the admission decision, not the streaming itself:
 * who gets in, who is turned away, and — the part with real consequences — that
 * everyone turned away receives the identical terminal frame. The orchestrator is
 * a spy; what it does with the blocks belongs to its own tests.
 *
 * Registry reset lives in both setUp and tearDown, following
 * DeferredRequestRegistryTest: this is process-wide static state, and a test that
 * empties it without restoring poisons every later test in the run.
 */
final class SseDeferredDoorTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $delivered = [];

    /** @var list<array<string, mixed>> */
    private array $written = [];

    /** @var list<string> */
    private array $spawned = [];

    /** @var list<array<string, mixed>> */
    private array $streamed = [];

    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
        DeferredRequestRegistry::reset();
        $this->delivered = [];
        $this->written = [];
        $this->spawned = [];
        $this->streamed = [];
    }

    protected function tearDown(): void
    {
        DeferredRequestRegistry::reset();
    }

    #[Test]
    public function a_wrong_bind_token_is_turned_away_without_consuming_the_request(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_1', 'demo.home', ['k' => 'v'], ['slot-a'], 'the-real-token');

        $opened = $this->door()->open(null, 'sess-1', 'dr_1', 'a-forged-token', null, false, false);

        self::assertFalse($opened);
        self::assertSame([self::terminalFrame()], $this->written, 'the terminal frame goes straight to the response');
        self::assertSame([], $this->delivered, 'nothing may be queued for a rejected redeem');
        self::assertNotNull(
            DeferredRequestRegistry::consume('dr_1'),
            'a rejected redeem must NOT burn the single-use entry — the legitimate holder can still use it',
        );
    }

    #[Test]
    public function a_matching_bind_token_opens_the_door(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_2', 'demo.home', ['k' => 'v'], ['slot-a'], 'good-token');

        $opened = $this->door()->open(null, 'sess-2', 'dr_2', 'good-token', null, false, false);

        self::assertTrue($opened);
        self::assertSame([], $this->written, 'an admitted request writes no terminal frame');
        self::assertSame([], $this->delivered, 'nor any terminal frame on the queue');
        self::assertCount(1, $this->streamed, 'the blocks are handed to the orchestrator exactly once');
    }

    #[Test]
    public function an_id_with_no_registry_entry_is_refused_before_anything_streams(): void
    {
        // Both registry reads go through DeferredRequestRegistry::consume(), so a
        // missing or expired entry is caught by the *first* of them: the bind-token
        // check fails and the door refuses without ever reaching the page lookup.
        //
        // This test previously claimed to cover the entry vanishing *between* the
        // two lookups, and did not: it stored one id and opened with another, so it
        // failed at the first check like this one does. That race is not reachable
        // from a unit test as the door stands — open() calls matchesBindToken() and
        // stream() back to back with no collaborator in between, and TTL expiry is
        // caught by the first read. Covering it would take a seam in the door.
        $this->bootRegistry();

        $door = new SseDeferredDoor(
            static fn (): object => new class {
                public function streamDeferredBlocks(...$args): void
                {
                    throw new \LogicException('must not stream an entry that is not there');
                }
            },
            function (string $session, array $data): void {
                $this->delivered[] = [$session, $data];
            },
            function (mixed $response, array $data): void {
                $this->written[] = $data;
            },
            function (callable $task, string $session): void {
                $this->spawned[] = $session;
            },
        );

        $opened = $door->open(null, 'sess-3', 'dr_never_minted', 'tok', null, false, false);

        self::assertFalse($opened, 'an id with no entry cannot satisfy the bind-token check');
        self::assertSame(
            [self::terminalFrame()],
            $this->written,
            'the unknown id is refused with the terminal frame',
        );
        self::assertSame([], $this->spawned, 'nothing is spawned for an id that was never minted');
    }

    #[Test]
    public function a_failure_while_streaming_is_reported_as_the_same_terminal_frame(): void
    {
        // Everything inside the streaming attempt is caught: a blown-up
        // orchestrator must still leave the client with a definite ending
        // rather than a stream that simply stops.
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_4', 'demo.home', ['k' => 'v'], ['slot-a'], 'tok');

        $door = new SseDeferredDoor(
            static fn (): object => new class {
                public function streamDeferredBlocks(...$args): void
                {
                    throw new \RuntimeException('orchestrator exploded');
                }
            },
            function (string $session, array $data): void {
                $this->delivered[] = [$session, $data];
            },
            function (mixed $response, array $data): void {
                $this->written[] = $data;
            },
            function (callable $task, string $session): void {
                $this->spawned[] = $session;
            },
        );

        $opened = $door->open(null, 'sess-4', 'dr_4', 'tok', null, false, false);

        self::assertTrue($opened, 'the door opened; the failure came later');
        self::assertSame([['sess-4', self::terminalFrame()]], $this->delivered);
    }

    #[Test]
    public function redeeming_a_deferred_id_does_not_remove_it(): void
    {
        // Documents existing behaviour rather than endorsing it: despite the
        // name, DeferredRequestRegistry::consume() only READS (it deletes solely
        // on TTL expiry), so an id stays redeemable for its whole lifetime. An
        // earlier version of this test asserted the opposite and passed only
        // because an unrelated exception was producing the same frame.
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_6', 'demo.home', ['k' => 'v'], ['slot-a'], 'tok');

        $door = $this->door();
        $door->open(null, 'sess-6', 'dr_6', 'tok', null, false, false);
        $door->open(null, 'sess-6', 'dr_6', 'tok', null, false, false);

        self::assertCount(2, $this->streamed, 'both redeems stream');
        self::assertSame([], $this->delivered, 'neither is abandoned');
    }

    #[Test]
    public function every_refusal_emits_the_byte_identical_terminal_frame(): void
    {
        // The client's `close` listener only fires deterministically if every
        // abandonment looks the same. This literal used to be written out in
        // four separate places in AsyncResourceSseServer.
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_5', 'demo.home', ['k' => 'v'], ['slot-a'], 'tok');

        $this->door()->open(null, 'sess-5', 'dr_5', 'wrong', null, false, false);   // bind-token refusal
        $this->door()->open(null, 'sess-5', 'dr_missing', 'tok', null, false, false); // unknown-id refusal

        $frames = array_merge(
            $this->written,
            array_map(static fn (array $d): array => $d[1], $this->delivered),
        );

        self::assertCount(2, $frames);
        self::assertSame($frames[0], $frames[1], 'both refusal paths must emit the same frame');
        self::assertFalse($frames[0]['reconnect'], 'a refused client must never be told to retry');
        self::assertTrue($frames[0]['close']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function terminalFrame(): array
    {
        return ['type' => 'done', 'live' => false, 'close' => true, 'reconnect' => false];
    }

    private function bootRegistry(): void
    {
        DeferredRequestRegistry::initialize(new IsomorphicConfig(
            enabled: true,
            deferredContextSize: 8192,
            requestSnapshotSize: 4096,
        ));
    }

    private function door(): SseDeferredDoor
    {
        return new SseDeferredDoor(
            function (): object {
                // DeferredBlockOrchestrator is final and cannot be mocked. The
                // door never type-hints what the resolver returns, so a
                // duck-typed double is both sufficient and honest about the
                // single method actually used.
                return new class ($this->streamed) {
                    /** @param list<array<string, mixed>> $streamed */
                    public function __construct(private array &$streamed)
                    {
                    }

                    public function streamDeferredBlocks(...$args): void
                    {
                        $this->streamed[] = $args;
                    }
                };
            },
            function (string $session, array $data): void {
                $this->delivered[] = [$session, $data];
            },
            function (mixed $response, array $data): void {
                $this->written[] = $data;
            },
            function (callable $task, string $session): void {
                $this->spawned[] = $session;
            },
        );
    }
}
