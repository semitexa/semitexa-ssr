<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\Attribute\Capability;

/**
 * Every SSR capability attribute must describe itself.
 *
 * The catalog agents read is derived from these declarations, so an attribute
 * added without one is a capability that exists and is invisible — which is the
 * exact failure this whole effort exists to fix, reintroduced one class at a
 * time. A doc page would not catch that; this does, at the moment the class is
 * added.
 */
final class CapabilityDeclarationTest extends TestCase
{
    private const ATTRIBUTE_DIR = __DIR__ . '/../../../src/Attribute';

    /** @return list<class-string> */
    private static function attributeClasses(): array
    {
        $classes = [];
        foreach ((array) glob(self::ATTRIBUTE_DIR . '/*.php') as $file) {
            $fqcn = 'Semitexa\\Ssr\\Attribute\\' . basename((string) $file, '.php');
            if (class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /** @return list<Capability> */
    private static function capabilities(): array
    {
        $out = [];
        foreach (self::attributeClasses() as $class) {
            foreach ((new ReflectionClass($class))->getAttributes(Capability::class) as $a) {
                $out[] = $a->newInstance();
            }
        }

        return $out;
    }

    #[Test]
    public function the_directory_is_not_empty(): void
    {
        // Guards the guard: a glob that silently matches nothing would make every
        // assertion below vacuously true.
        self::assertNotEmpty(self::attributeClasses(), 'no attribute classes found to check');
    }

    #[Test]
    public function every_attribute_class_declares_a_capability(): void
    {
        $missing = [];
        foreach (self::attributeClasses() as $class) {
            if ((new ReflectionClass($class))->getAttributes(Capability::class) === []) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing, 'these declare a framework ability but describe none of it: ' . implode(', ', $missing));
    }

    #[Test]
    public function ids_are_unique(): void
    {
        // Verify findings point at an id. Two capabilities answering to one id
        // means a finding sends the reader to the wrong mechanism.
        $ids = array_map(static fn (Capability $c): string => $c->id, self::capabilities());

        self::assertSame(array_values(array_unique($ids)), $ids, 'duplicate capability id');
    }

    #[Test]
    public function ids_are_area_prefixed_slugs(): void
    {
        foreach (self::capabilities() as $c) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9]*\.[a-z][a-z0-9-]*$/', $c->id);
            self::assertStringStartsWith('ssr.', $c->id, 'SSR capabilities live under the ssr. area');
        }
    }

    #[Test]
    public function every_capability_says_when_to_reach_for_it(): void
    {
        // The summary alone does not change behaviour — someone has to recognise
        // their own situation in it. useWhen is the half that decides whether a
        // capability gets used, so an empty one is a silent no-op.
        foreach (self::capabilities() as $c) {
            self::assertNotSame('', trim($c->summary), $c->id . ' has no summary');
            self::assertNotSame('', trim($c->useWhen), $c->id . ' does not say when to use it');
            // A capability described only by its upside gets applied everywhere,
            // and over-application discredits the catalog faster than omission.
            self::assertNotSame('', trim($c->avoidWhen), $c->id . ' does not say when NOT to use it');
        }
    }

    #[Test]
    public function see_also_points_at_a_capability_that_exists(): void
    {
        // A pointer to a renamed or deleted id is worse than none: it sends the
        // reader looking for something that is not there.
        $known = array_map(static fn (Capability $c): string => $c->id, self::capabilities());
        // Cross-area references are legitimate (ui.* -> ssr.*), so only same-area
        // pointers are resolvable from inside this package.
        foreach (self::capabilities() as $c) {
            if ($c->seeAlso === '' || !str_starts_with($c->seeAlso, 'ssr.')) {
                continue;
            }
            self::assertContains($c->seeAlso, $known, $c->id . ' points at unknown capability ' . $c->seeAlso);
        }
    }

    #[Test]
    public function replaces_entries_name_something_concrete(): void
    {
        // These are what the verify rules key on. A vague entry ("doing it
        // manually") cannot be detected in code, so it would produce a rule that
        // either never fires or fires on everything.
        foreach (self::capabilities() as $c) {
            foreach ($c->replaces as $entry) {
                self::assertGreaterThan(
                    20,
                    strlen(trim($entry)),
                    $c->id . ' has a replaces entry too vague to detect: ' . $entry,
                );
            }
        }
    }
}
