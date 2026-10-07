<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Ssr\Application\Service\Asset\AssetEntry;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;

/**
 * A page with scripts tells them the largest request the server takes, so a
 * form whose files would not fit is stopped in the browser with a message —
 * not sent and dropped by Swoole, which the visitor sees as nothing at all
 * (iPhone photos into apartmens, 2026-10-06).
 */
final class RequestLimitMetaTest extends TestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $value = getenv('SWOOLE_PACKAGE_MAX_LENGTH');
        $this->previous = $value === false ? null : $value;
    }

    protected function tearDown(): void
    {
        putenv($this->previous === null ? 'SWOOLE_PACKAGE_MAX_LENGTH' : 'SWOOLE_PACKAGE_MAX_LENGTH=' . $this->previous);
    }

    /** @param list<AssetEntry> $entries */
    private function prelude(array $entries): string
    {
        return (string) (new \ReflectionMethod(AssetRenderer::class, 'renderScriptPrelude'))->invoke(null, $entries);
    }

    private function module(): AssetEntry
    {
        return new AssetEntry(key: 'demo:js:core', module: 'demo', type: 'js', path: 'js/core.js', specifier: 'demo/core');
    }

    #[Test]
    public function a_page_with_scripts_carries_the_request_limit_before_its_import_map(): void
    {
        putenv('SWOOLE_PACKAGE_MAX_LENGTH');
        $html = $this->prelude([$this->module()]);

        self::assertStringStartsWith('<meta name="semitexa-request-max" content="33554432">', $html);
        self::assertStringContainsString('<script type="importmap"', $html);

        putenv('SWOOLE_PACKAGE_MAX_LENGTH=67108864');
        self::assertStringStartsWith('<meta name="semitexa-request-max" content="67108864">', $this->prelude([$this->module()]));
        self::assertSame(67_108_864, Environment::requestLimit());
    }

    #[Test]
    public function a_page_without_scripts_is_left_as_it_was(): void
    {
        self::assertSame('', $this->prelude([]));
    }
}
