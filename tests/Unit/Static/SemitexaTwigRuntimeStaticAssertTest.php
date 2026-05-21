<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Static;

use PHPUnit\Framework\TestCase;

/**
 * The client-side runtime (semitexa-twig.js) is plain ES5 served as a static
 * asset — it has no PHP-side counterpart we can compile-check. To guard against
 * regressions that would silently break the deferred-component delivery cycle,
 * this test asserts that the on-disk file still contains the SSE handler hooks
 * and selectors the server side depends on.
 */
final class SemitexaTwigRuntimeStaticAssertTest extends TestCase
{
    private const RUNTIME_PATH = __DIR__ . '/../../../src/Application/Static/js/semitexa-twig.js';

    private string $source;

    protected function setUp(): void
    {
        $real = realpath(self::RUNTIME_PATH);
        if ($real === false) {
            self::fail('semitexa-twig.js runtime not found at ' . self::RUNTIME_PATH);
        }
        $contents = file_get_contents($real);
        if ($contents === false) {
            self::fail('Could not read semitexa-twig.js runtime');
        }
        $this->source = $contents;
    }

    public function testHandlesDeferredComponentFrameType(): void
    {
        self::assertStringContainsString(
            "payload.type === 'deferred_component'",
            $this->source,
            'Runtime must branch on the deferred_component SSE frame type emitted by DeferredBlockOrchestrator',
        );
    }

    public function testTargetsComponentPlaceholderSelector(): void
    {
        self::assertStringContainsString(
            'data-ssr-deferred-component=',
            $this->source,
            'Runtime must query for the component-instance placeholder selector',
        );
        self::assertStringContainsString(
            'data-ssr-component-instance=',
            $this->source,
            'Runtime must qualify the placeholder selector with the instance id',
        );
    }

    public function testFiresComponentRenderedCustomEvent(): void
    {
        self::assertStringContainsString(
            "semitexa:component:rendered",
            $this->source,
            'Runtime must fire semitexa:component:rendered after swapping a deferred component placeholder',
        );
    }

    public function testTracksPendingComponentsFromManifest(): void
    {
        self::assertStringContainsString(
            'manifest.components',
            $this->source,
            'Runtime must read manifest.components to track pending component instances',
        );
    }

    public function testStillHandlesLayoutSlotDeferredBlocks(): void
    {
        // Hard rule: do NOT break existing data-ssr-deferred (layout slot) handling.
        self::assertStringContainsString(
            "payload.type === 'deferred_block'",
            $this->source,
            'Existing layout-slot deferred_block handling must remain intact',
        );
        self::assertStringContainsString(
            '[data-ssr-deferred="',
            $this->source,
            'Existing layout-slot selector must remain intact',
        );
    }
}
