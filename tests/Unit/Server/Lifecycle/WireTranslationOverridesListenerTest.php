<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Server\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTranslationOverridesListener;

/**
 * Which installed packages get their locale catalog loaded.
 *
 * The dir used to be derived from a module's TEMPLATE path only (locales is
 * its sibling). A package that ships no Twig templates — semitexa/cms renders
 * its console as standalone HTML — has no template path, so its catalog was
 * never registered and every key fell back to English in every language,
 * with nothing anywhere saying so.
 */
final class WireTranslationOverridesListenerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssr-locales-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/with-templates/src/Application/View/templates', 0777, true);
        mkdir($this->root . '/with-templates/src/Application/View/locales', 0777, true);
        mkdir($this->root . '/no-templates/src/Application/View/locales', 0777, true);
        mkdir($this->root . '/no-locales/src', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_package_with_templates_keeps_the_sibling_locales_dir(): void
    {
        $dirs = WireTranslationOverridesListener::packageLocaleDirs([
            $this->module('semitexa-os', ['semitexa-os', 'os', 'project-layouts-Os'], 'with-templates', true),
        ]);

        self::assertSame(['os' => $this->root . '/with-templates/src/Application/View/locales'], $dirs);
    }

    #[Test]
    public function a_package_without_templates_still_has_its_catalog_registered(): void
    {
        $dirs = WireTranslationOverridesListener::packageLocaleDirs([
            $this->module('semitexa-cms', ['semitexa-cms', 'cms'], 'no-templates', false),
        ]);

        self::assertSame(['cms' => $this->root . '/no-templates/src/Application/View/locales'], $dirs);
    }

    #[Test]
    public function a_package_that_ships_no_catalog_is_left_out(): void
    {
        $dirs = WireTranslationOverridesListener::packageLocaleDirs([
            $this->module('semitexa-mail', ['semitexa-mail', 'mail'], 'no-locales', false),
        ]);

        self::assertSame([], $dirs);
    }

    /**
     * @param list<string> $aliases
     * @return array{name: string, path: string, aliases: list<string>, templatePaths: list<string>}
     */
    private function module(string $name, array $aliases, string $dir, bool $templates): array
    {
        $path = $this->root . '/' . $dir;

        return [
            'name' => $name,
            'path' => $path,
            'aliases' => $aliases,
            'templatePaths' => $templates ? [$path . '/src/Application/View/templates'] : [],
        ];
    }
}
