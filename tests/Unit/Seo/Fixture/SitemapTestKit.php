<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo\Fixture;

use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapProviderRegistry;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrlProviderInterface;

/** Builds a SitemapGenerator over a fixed provider list, without discovery or a container. */
final class SitemapTestKit
{
    /**
     * @param list<class-string<SitemapUrlProviderInterface>> $providers in priority order
     */
    public static function generator(array $providers): SitemapGenerator
    {
        $registry = new SitemapProviderRegistry();
        $list = [];
        foreach ($providers as $index => $class) {
            $list[] = ['class' => $class, 'priority' => ($index + 1) * 10];
        }
        self::set($registry, 'providers', $list);

        $generator = new SitemapGenerator();
        self::set($generator, 'registry', $registry);

        return $generator;
    }

    public static function set(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setAccessible(true);
        $ref->setValue($target, $value);
    }
}
