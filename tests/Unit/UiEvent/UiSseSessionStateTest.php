<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\UiEvent;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;

/**
 * The session id ssr's deferred pipeline carries across the request boundary.
 *
 * Owned here since the move out of platform-ui: the three callers that need it —
 * HtmlResponse, DeferredBlockOrchestrator and DeferredRequestRegistry — are all
 * ssr's, and used to reach it through `class_exists()` because ssr cannot
 * require platform-ui without closing a dependency cycle.
 *
 * Every test restores whatever id was set on entry. The holder is shared
 * per-coroutine process-wide, so a test that resets it and walks away changes
 * what every later test in the same process sees.
 */
final class UiSseSessionStateTest extends TestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = UiSseSessionState::current();
        UiSseSessionState::reset();
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            UiSseSessionState::reset();

            return;
        }
        UiSseSessionState::restore($this->previous);
    }

    public function testCurrentIsNullBeforeAnythingIsMinted(): void
    {
        self::assertNull(UiSseSessionState::current());
    }

    public function testMintIfAbsentProducesACanonicalBearerShapedId(): void
    {
        $id = UiSseSessionState::mintIfAbsent();

        // `sse_` + 32 hex. The KISS endpoint admits on this shape, so a change
        // here silently breaks the converged deferred stream rather than failing
        // a boot.
        self::assertMatchesRegularExpression('/\Asse_[a-f0-9]{32}\z/', $id);
        self::assertSame($id, UiSseSessionState::current());
    }

    public function testMintIfAbsentIsStableWithinTheSameRequest(): void
    {
        // The whole point: every component on a page must fold the SAME `sub`
        // claim into its manifest, or the runtime opens one SSE connection per
        // component.
        self::assertSame(UiSseSessionState::mintIfAbsent(), UiSseSessionState::mintIfAbsent());
    }

    public function testResetClearsTheId(): void
    {
        UiSseSessionState::mintIfAbsent();
        UiSseSessionState::reset();

        self::assertNull(UiSseSessionState::current());
    }

    public function testRestoreAdoptsASafeId(): void
    {
        UiSseSessionState::restore('sse_live_page_session_01');

        self::assertSame('sse_live_page_session_01', UiSseSessionState::current());
    }

    public function testRestoreIgnoresAnUnsafeIdRatherThanThrowing(): void
    {
        // A malformed stored id must never break deferred rendering — the caller
        // falls back to a fresh mint instead.
        UiSseSessionState::restore('nope not an id');

        self::assertNull(UiSseSessionState::current());
    }

    public function testSetForTestingRejectsAnUnsafeId(): void
    {
        // The test seam is the one place that DOES throw: a test seeding a shape
        // the manifest builder would reject is a broken test, not a runtime edge.
        $this->expectException(\InvalidArgumentException::class);
        UiSseSessionState::setForTesting('nope not an id');
    }

    public function testTheDeprecatedShimSharesTheSameState(): void
    {
        // The shim at the old platform-ui FQCN forwards here rather than holding
        // its own copy. If that ever stops being true, a request that mints
        // through one name and reads through the other gets two different
        // channels and the patches go nowhere.
        $shim = \Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState::class;
        if (!class_exists($shim)) {
            self::markTestSkipped('platform-ui is not installed in this app.');
        }

        $minted = UiSseSessionState::mintIfAbsent();
        self::assertSame($minted, $shim::current());

        $shim::reset();
        self::assertNull(UiSseSessionState::current());
    }
}
