<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseReRunScope;
use Swoole\Coroutine;

/**
 * The re-run reentrancy invariant, tested directly for the first time.
 *
 * Until `tk-sse-rerun-scope` this depth was a private static reached only by
 * reflection, so the property that actually matters — that two coroutines do
 * NOT see each other's depth — had no test at all. It is the one that would
 * hurt: a leak across coroutines makes an unrelated connection take its JSON
 * branch and never open its stream.
 */
final class SseReRunScopeTest extends TestCase
{
    #[Test]
    public function a_fresh_scope_is_not_in_progress(): void
    {
        self::assertFalse((new SseReRunScope())->isInProgress());
    }

    #[Test]
    public function begin_opens_and_end_closes(): void
    {
        $scope = new SseReRunScope();

        $scope->begin();
        self::assertTrue($scope->isInProgress());

        $scope->end();
        self::assertFalse($scope->isInProgress());
    }

    #[Test]
    public function nested_re_runs_need_every_end_before_the_scope_closes(): void
    {
        // Depth, not a boolean: an inner re-run finishing must not convince the
        // outer one that it is no longer re-running.
        $scope = new SseReRunScope();

        $scope->begin();
        $scope->begin();
        $scope->end();

        self::assertTrue($scope->isInProgress(), 'the outer re-run is still in progress');

        $scope->end();
        self::assertFalse($scope->isInProgress());
    }

    #[Test]
    public function an_unbalanced_end_cannot_drive_the_depth_negative(): void
    {
        // A negative depth would wedge every LATER re-run into reporting "not in
        // progress" — the clamp is what stops one unbalanced call from poisoning
        // the whole worker.
        $scope = new SseReRunScope();

        $scope->end();
        $scope->end();
        $scope->begin();

        self::assertTrue($scope->isInProgress());
    }

    #[Test]
    public function the_depth_is_coroutine_local(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        // THE invariant. A re-run yields on I/O to another session's connect
        // coroutine; that coroutine must see its own depth, not the re-running
        // one's. A per-worker counter would report true here and the sibling
        // connection would never open its stream.
        $scope = new SseReRunScope();
        $sibling = null;
        $outerAfterSibling = null;

        Coroutine\run(static function () use ($scope, &$sibling, &$outerAfterSibling): void {
            $scope->begin();

            $done = new Coroutine\Channel(1);
            Coroutine::create(static function () use ($scope, &$sibling, $done): void {
                $sibling = $scope->isInProgress();
                $done->push(true);
            });
            $done->pop(1.0);

            $outerAfterSibling = $scope->isInProgress();
            $scope->end();
        });

        self::assertFalse($sibling, 'a sibling coroutine must NOT inherit the re-run scope');
        self::assertTrue($outerAfterSibling, 'and the re-running coroutine keeps its own');
    }

    #[Test]
    public function a_child_coroutine_cannot_close_the_parents_scope(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $scope = new SseReRunScope();
        $parentStillOpen = null;

        Coroutine\run(static function () use ($scope, &$parentStillOpen): void {
            $scope->begin();

            $done = new Coroutine\Channel(1);
            Coroutine::create(static function () use ($scope, $done): void {
                $scope->end();
                $done->push(true);
            });
            $done->pop(1.0);

            $parentStillOpen = $scope->isInProgress();
            $scope->end();
        });

        self::assertTrue($parentStillOpen, 'the child clamps its own depth at zero, leaving the parent alone');
    }

    #[Test]
    public function the_non_coroutine_fallback_is_process_wide(): void
    {
        // Outside a coroutine (CLI, unit tests) there is no context to scope to,
        // so the instance-level counter answers instead — which is exactly what
        // the handler tests rely on when they open the scope by hand.
        $scope = new SseReRunScope();

        $scope->begin();
        self::assertTrue($scope->isInProgress());
        $scope->end();

        self::assertFalse($scope->isInProgress());
    }

    #[Test]
    public function two_scopes_do_not_share_the_fallback_counter(): void
    {
        // Instance state, not static: the facade keeps ONE per worker, but a
        // separately constructed scope must be independent or tests would leak
        // into each other.
        $a = new SseReRunScope();
        $b = new SseReRunScope();

        $a->begin();

        self::assertTrue($a->isInProgress());
        self::assertFalse($b->isInProgress());
    }
}
