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
}
