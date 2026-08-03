<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapJsonRenderer;

/**
 * Pins the route-array access contract.
 *
 * AttributeDiscovery emits an 'access' key with values public/protected/service.
 * It has never emitted 'public'. Three consumers nonetheless read $route['public'],
 * each with a different default, which left /sitemap.json and /sitemap.xml shipping
 * empty documents while the dev route graph reported every route as public.
 *
 * The first test pins the behaviour; the second stops the phantom key returning.
 */
final class RouteAccessContractTest extends TestCase
{
    /**
     * @param array<string, mixed> $route
     * @dataProvider routeProvider
     */
    public function test_eligibility_follows_the_access_key(array $route, bool $expected): void
    {
        $method = (new \ReflectionClass(AiSitemapJsonRenderer::class))
            ->getMethod('isEligibleRoute');
        $method->setAccessible(true);

        self::assertSame(
            $expected,
            $method->invoke(new AiSitemapJsonRenderer(), $route),
            'route: ' . json_encode($route)
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function routeProvider(): array
    {
        $base = ['path' => '/things', 'methods' => ['GET']];

        return [
            // Real routes carry the enum; hand-built arrays may carry its value.
            'enum public is eligible'      => [$base + ['accessType' => PayloadAccessType::Public], true],
            'string public is eligible'    => [$base + ['accessType' => 'public'], true],
            'enum protected is not'        => [$base + ['accessType' => PayloadAccessType::Protected], false],
            'missing accessType is not'    => [$base, false],
            // Two keys were assumed at different times and neither exists on a raw
            // route. Both must stay inert, or the endpoints go silently empty again.
            'phantom public key inert'     => [$base + ['public' => true], false],
            'phantom access key inert'     => [$base + ['access' => 'public'], false],
            'internal paths excluded'      => [['path' => '/__semitexa_x', 'methods' => ['GET'], 'accessType' => PayloadAccessType::Public], false],
            'non-GET excluded'             => [['path' => '/things', 'methods' => ['POST'], 'accessType' => PayloadAccessType::Public], false],
        ];
    }

    public function test_no_source_file_reads_the_phantom_public_key(): void
    {
        $src = \dirname(__DIR__, 3) . '/src';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (preg_match('/\$route\[\s*[\'"](?:public|access)[\'"]\s*\]/', $body) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'Raw routes carry accessType only; access and public are phantoms.');
    }
}
