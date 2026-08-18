<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetEntry;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;
use Semitexa\Ssr\Application\Service\Asset\ScriptNonceSource;

/**
 * A consumer enforcing `script-src 'nonce-…'` needs the pipeline's own inline
 * scripts — the import map above all — to carry the nonce, or every
 * type="module" runtime dies the moment CSP turns on. And a consumer that
 * never registers a provider must get byte-identical markup to before the
 * seam existed.
 */
final class ScriptNonceTest extends TestCase
{
    protected function tearDown(): void
    {
        ScriptNonceSource::register(null);
    }

    private function importMap(): string
    {
        $entry = new AssetEntry(
            key: 'demo:js:core',
            module: 'demo',
            type: 'js',
            path: 'js/core.js',
            specifier: 'demo/core',
        );
        $m = new \ReflectionMethod(AssetRenderer::class, 'renderImportMap');

        return (string) $m->invoke(null, [$entry]);
    }

    public function testNoProviderMeansTheMarkupOfOld(): void
    {
        $html = $this->importMap();

        self::assertStringContainsString('<script type="importmap">', $html);
        self::assertStringNotContainsString('nonce', $html);
    }

    public function testRegisteredProviderStampsTheImportMap(): void
    {
        ScriptNonceSource::register(static fn (): string => 'abc123==');

        self::assertStringContainsString('<script type="importmap" nonce="abc123=="', $this->importMap());
    }

    public function testProviderNonceReplacesAManifestDeclaredOne(): void
    {
        $m = new \ReflectionMethod(AssetRenderer::class, 'inlineScriptAttributes');

        // Without a provider the manifest's own attribute is kept as-is.
        $attrs = (string) $m->invoke(null, ['nonce' => 'stale-manifest-value', 'defer' => 'defer']);
        self::assertSame(1, substr_count($attrs, 'nonce='));
        self::assertStringContainsString('stale-manifest-value', $attrs);

        ScriptNonceSource::register(static fn (): string => 'live-nonce');
        $attrs = (string) $m->invoke(null, ['nonce' => 'stale-manifest-value', 'defer' => 'defer']);
        self::assertSame(1, substr_count($attrs, 'nonce='));
        self::assertStringContainsString('nonce="live-nonce"', $attrs);
        self::assertStringNotContainsString('stale-manifest-value', $attrs);
        self::assertStringContainsString('defer', $attrs);
    }

    public function testNonceIsEscapedAndEmptyNonceIsOmitted(): void
    {
        ScriptNonceSource::register(static fn (): string => '"><script>');
        self::assertStringContainsString('nonce="&quot;&gt;&lt;script&gt;"', $this->importMap());

        ScriptNonceSource::register(static fn (): string => '');
        self::assertStringNotContainsString('nonce', $this->importMap());
    }
}
