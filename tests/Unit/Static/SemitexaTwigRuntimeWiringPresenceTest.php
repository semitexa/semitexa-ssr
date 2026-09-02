<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Static;

use PHPUnit\Framework\TestCase;

/**
 * Guards that the client runtime still CONTAINS the SSE wiring the server depends on.
 *
 * ⚠️ Read the name literally: every assertion here is a substring search over
 * semitexa-twig.js as text. Nothing is executed, so this file would stay green if the
 * runtime were syntactically broken or rendered nonsense - it pins the presence of hook
 * names and selectors, never behaviour. It was previously called
 * SemitexaTwigRuntimeStaticAssertTest, which invited being mistaken for coverage of the
 * runtime; the rename is the point of it.
 *
 * Its original justification - that the runtime has no counterpart we can check - is no
 * longer true for the template engine. {@see \Semitexa\Ssr\Tests\Unit\Isomorphic\RenderParityTest}
 * now executes this exact file through node and asserts its output matches PHP Twig
 * character for character.
 *
 * What remains genuinely unexecutable HERE is the transport half: the SSE lifecycle needs
 * an EventSource and a DOM, so it belongs in a browser test rather than in node. Until
 * something covers that, checking the hook names still exist is worth more than nothing -
 * it just must not be mistaken for more than it is.
 */
final class SemitexaTwigRuntimeWiringPresenceTest extends TestCase
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
