<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetEntry;

/**
 * Manifest entries are decoded JSON. A field of the wrong type falls back the
 * way the registry already treats overrides, instead of reaching a typed
 * constructor and taking the manifest down with it.
 */
final class AssetEntryManifestTest extends TestCase
{
    #[Test]
    public function a_well_formed_definition_is_kept_as_written(): void
    {
        $entry = AssetEntry::fromManifest('ui:js:core', 'ui', [
            'type' => 'js',
            'path' => 'js/core.js',
            'attributes' => ['type' => 'module', 'defer' => true],
            'dependencies' => ['ui:js:dates'],
            'specifier' => 'ui/core',
        ]);

        self::assertSame(['type' => 'module', 'defer' => true], $entry->attributes);
        self::assertSame(['ui:js:dates'], $entry->dependencies);
        self::assertSame('ui/core', $entry->specifier);
    }

    #[Test]
    public function wrongly_typed_fields_fall_back_instead_of_failing(): void
    {
        $entry = AssetEntry::fromManifest('ui:js:core', 'ui', [
            'type' => 'js',
            'path' => 'js/core.js',
            'attributes' => ['defer' => true, 0 => 'stray'],
            'dependencies' => ['ui:js:dates', 7, ['nested']],
            'specifier' => 42,
        ]);

        self::assertSame(['defer' => true], $entry->attributes);
        self::assertSame(['ui:js:dates'], $entry->dependencies);
        self::assertNull($entry->specifier);
    }

    #[Test]
    public function overrides_keep_what_they_do_not_name(): void
    {
        $entry = AssetEntry::fromManifest('ui:js:core', 'ui', [
            'path' => 'js/core.js',
            'dependencies' => ['ui:js:dates'],
            'specifier' => 'ui/core',
            'attributes' => ['defer' => true],
        ]);

        $same = $entry->withOverrides(['dependencies' => null, 'specifier' => 3]);
        self::assertSame(['ui:js:dates'], $same->dependencies);
        self::assertSame('ui/core', $same->specifier);

        $changed = $entry->withOverrides(['dependencies' => ['ui:js:x', false], 'attributes' => ['async' => true]]);
        self::assertSame(['ui:js:x'], $changed->dependencies);
        self::assertSame(['defer' => true, 'async' => true], $changed->attributes);
    }

    #[Test]
    public function wrongly_typed_scalar_fields_fall_back_to_their_defaults(): void
    {
        $entry = AssetEntry::fromManifest('ui:css:core', 'ui', [
            'type' => ['css'],
            'path' => 5,
            'scope' => false,
            'position' => 1,
            'priority' => 'high',
        ]);

        self::assertSame('js', $entry->type);
        self::assertSame('', $entry->path);
        self::assertSame('page', $entry->scope);
        self::assertSame('body', $entry->position);
        self::assertSame(100, $entry->priority);

        $same = AssetEntry::fromManifest('ui:css:core', 'ui', ['type' => 'css', 'path' => 'css/core.css', 'priority' => 5])
            ->withOverrides(['module' => 1, 'type' => 2, 'path' => [], 'scope' => 3, 'position' => 4, 'priority' => '9']);
        self::assertSame('ui', $same->module);
        self::assertSame('css', $same->type);
        self::assertSame('css/core.css', $same->path);
        self::assertSame('page', $same->scope);
        self::assertSame('head', $same->position);
        self::assertSame(5, $same->priority);
    }
}
