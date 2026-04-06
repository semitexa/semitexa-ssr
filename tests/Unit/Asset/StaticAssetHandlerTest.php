<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Asset\StaticAssetHandler;

final class StaticAssetHandlerTest extends TestCase
{
    #[Test]
    public function match_prefix_accepts_assets_and_static_routes(): void
    {
        $method = new \ReflectionMethod(StaticAssetHandler::class, 'matchPrefix');
        $method->setAccessible(true);

        self::assertSame('/assets/', $method->invoke(null, '/assets/semitexa-site/css/site.css?v=1'));
        self::assertSame('/static/', $method->invoke(null, '/static/semitexa-site/css/site.css?v=1'));
        self::assertNull($method->invoke(null, '/foo/semitexa-site/css/site.css?v=1'));
    }
}
