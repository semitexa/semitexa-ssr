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
    public function an_id_that_vanishes_between_the_two_lookups_is_abandoned(): void
    {
        // The door reads the registry twice — once to check the bind token, once
        // to fetch the page — so an entry can legitimately disappear in between
        // (TTL expiry, a worker reset). The client must then be told to stop
        // rather than left waiting on a stream that will never start.
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_3', 'demo.home', ['k' => 'v'], ['slot-a'], 'tok');

        $door = new SseDeferredDoor(
            static fn (): object => new class {
                public function streamDeferredBlocks(...$args): void
                {
                    throw new \LogicException('must not stream a vanished entry');
                }
            },
            function (string $session, array $data): void {
                $this->delivered[] = [$session, $data];
                // Drop the entry the moment the bind-token check has passed,
                // reproducing the race without reaching into the door.
            },
            function (mixed $response, array $data): void {
                $this->written[] = $data;
            },
            function (callable $task, string $session): void {
                $this->spawned[] = $session;
            },
        );

        DeferredRequestRegistry::remove('dr_3_gone');
        $opened = $door->open(null, 'sess-3', 'dr_3_gone', 'tok', null, false, false);

        self::assertFalse($opened, 'an id with no entry cannot satisfy the bind-token check');
        self::assertSame(
            [self::terminalFrame()],
            $this->written,
            'the never-minted id is refused with the terminal frame',
        );
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
