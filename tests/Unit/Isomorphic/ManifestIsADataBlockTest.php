<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;

/**
 * The manifest under a strict CSP.
 *
 * Measured in Chrome 2026-09-16 against `script-src 'self' 'nonce-…'` with no
 * unsafe-inline: the old `<script>window.__SSR_DEFERRED={…}</script>` was
 * refused, the runtime loaded anyway (it is a src= script that 'self' allows),
 * and the page sat there with resolved skeletons it could never fill. The
 * response was a correct 200 the whole time — the only witness was the browser
 * console, which is why this is pinned here as a shape and in an E2E spec as
 * behaviour.
 *
 * The rule these tests state: the manifest is DATA. It must not be executable,
 * so it must never need a nonce to survive.
 */
final class ManifestIsADataBlockTest extends TestCase
{
    protected function setUp(): void
    {
        CspNonce::reset();
        // renderRuntimeScript() resolves the asset through the registry; an
        // empty one is enough — the path falls back and the ?v= is 0.
        ModuleAssetRegistry::setModuleRegistry(new ModuleRegistry());
    }

    protected function tearDown(): void
    {
        CspNonce::reset();
        ModuleAssetRegistry::reset();
    }

    private function manifest(): string
    {
        return PlaceholderRenderer::renderManifest(
            'dr_abc',
            'sse_def',
            [new DeferredSlotDefinition(
                slotId: 'ssrp_fast',
                templateName: 'fast.html.twig',
                pageHandle: 'ssrp_page',
                priority: 2,
            )],
            'bind_xyz',
        );
    }

    #[Test]
    public function theManifestIsAJsonDataBlockAndNotAnAssignment(): void
    {
        $html = $this->manifest();

        self::assertStringContainsString('<script type="application/json" data-ssr-deferred-manifest>', $html);
        self::assertStringNotContainsString(
            'window.__SSR_DEFERRED=',
            $html,
            'an assignment is executable, and an executable inline script is what a nonce policy refuses'
        );
    }

    #[Test]
    public function theBlockCarriesNoNonceEvenWhenTheApplicationHasOne(): void
    {
        CspNonce::set('per-request-nonce');

        $html = $this->manifest();

        // Asserted present before asserted clean: an empty render satisfies
        // the negative below and would hide the manifest going missing.
        self::assertStringContainsString('data-ssr-deferred-manifest', $html, 'the block is rendered at all');
        self::assertStringNotContainsString(
            'nonce',
            $html,
            'script-src does not govern a data block; a nonce on it would imply it needs one'
        );
    }

    #[Test]
    public function thePayloadIsParseableJsonCarryingTheSlotContract(): void
    {
        $html = $this->manifest();

        $json = $this->payloadOf($html);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('dr_abc', $decoded['requestId']);
        self::assertSame('sse_def', $decoded['sessionId']);
        self::assertSame('bind_xyz', $decoded['bindToken']);
        self::assertSame('ssrp_fast', $decoded['slots'][0]['id']);
        self::assertSame(2, $decoded['slots'][0]['priority']);
    }

    #[Test]
    public function aPayloadCannotCloseTheTagItSitsIn(): void
    {
        // The bind token is the one field that carries caller-influenced text
        // into the block. Inside a data block a literal `</script` ends the
        // element, and everything after it becomes markup — JSON_HEX_TAG is
        // what stops that, so this asserts the escape, not the intent.
        $html = PlaceholderRenderer::renderManifest(
            'dr_abc',
            'sse_def',
            [],
            '</script><img src=x onerror=alert(1)>',
        );

        self::assertStringNotContainsString('</script><img', $html);
        self::assertSame(1, substr_count($html, '</script>'), 'exactly one closer: the real one');

        $decoded = json_decode($this->payloadOf($html), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            '</script><img src=x onerror=alert(1)>',
            $decoded['bindToken'],
            'escaped on the wire, intact after parsing'
        );
    }

    #[Test]
    public function theRuntimeScriptCarriesTheNonceWhenThereIsOne(): void
    {
        CspNonce::set('per-request-nonce');

        $html = PlaceholderRenderer::renderRuntimeScript();

        self::assertStringContainsString('src="/assets/ssr/js/semitexa-twig.js', $html);
        self::assertStringContainsString(' nonce="per-request-nonce"', $html);
    }

    #[Test]
    public function theRuntimeScriptIsUnchangedForAConsumerWithNoCsp(): void
    {
        $html = PlaceholderRenderer::renderRuntimeScript();

        // Same rule as above: prove the script is there before proving what
        // it does not carry.
        self::assertStringContainsString('src=', $html, 'the runtime script is rendered at all');
        self::assertStringNotContainsString('nonce', $html, 'zero cost for a consumer with no policy');
    }

    private function payloadOf(string $html): string
    {
        self::assertSame(1, preg_match('#<script[^>]*>(.*)</script>#s', $html, $m), 'one script block');

        return $m[1];
    }
}
