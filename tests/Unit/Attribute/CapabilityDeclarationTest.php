<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Attribute\InternalAttribute;

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

            // Loudly, not by skipping. A file whose class does not match its
            // name is exactly the drift this guard exists to catch, and
            // dropping it silently would let a new attribute arrive
            // unclassified while every assertion below still passed.
            self::assertTrue(
                class_exists($fqcn),
                basename((string) $file) . ' does not declare ' . $fqcn . ' — namespace or class name drift',
            );

            // Only real attributes. The question this file asks — advertised or
            // internal? — has no meaning for a support class that happens to
            // sit in this directory, and demanding an answer would force a
            // marker onto something that is neither.
            if ((new ReflectionClass($fqcn))->getAttributes(Attribute::class) === []) {
                continue;
            }

            $classes[] = $fqcn;
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
    public function every_attribute_class_is_either_advertised_or_declared_internal(): void
    {
        // Undecided is the failure mode. An attribute with neither marker is a
        // capability nobody classified, and the way a real one quietly becomes
        // invisible. Requiring a decision — not requiring a capability — is what
        // lets this same guard apply to packages that are mostly plumbing.
        $undecided = [];
        foreach (self::attributeClasses() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes(Capability::class) === []
                && $reflection->getAttributes(InternalAttribute::class) === []) {
                $undecided[] = $class;
            }
        }

        self::assertSame([], $undecided, 'neither #[Capability] nor #[InternalAttribute]: ' . implode(', ', $undecided));
    }

    #[Test]
    public function nothing_claims_to_be_both(): void
    {
        // Collect, then assert once: a loop of assertions performs none at all
        // when the collection is empty, and a test that quietly asserts nothing
        // is the shape this whole file exists to prevent.
        $both = [];
        foreach (self::attributeClasses() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes(Capability::class) !== []
                && $reflection->getAttributes(InternalAttribute::class) !== []) {
                $both[] = $class;
            }
        }

        self::assertSame([], $both, 'declared both advertised and internal: ' . implode(', ', $both));
    }

    #[Test]
    public function every_internal_attribute_records_why(): void
    {
        // "Internal" with no reason is an opt-out, not a decision. The reason is
        // written for whoever next asks the same question about a neighbouring
        // attribute.
        $unexplained = [];
        foreach (self::attributeClasses() as $class) {
            foreach ((new ReflectionClass($class))->getAttributes(InternalAttribute::class) as $a) {
                if (trim($a->newInstance()->reason) === '') {
                    $unexplained[] = $class;
                }
            }
        }

        self::assertSame([], $unexplained, 'internal without saying why: ' . implode(', ', $unexplained));
    }

    #[Test]
    public function every_capability_names_what_it_replaces(): void
    {
        // The criterion, enforced. A capability is an ability someone can MISS,
        // and the operational form of that is being able to name what they would
        // have built instead. Nothing to name means nothing to miss, which means
        // plumbing.
        foreach (self::capabilities() as $c) {
            self::assertNotEmpty(
                $c->replaces,
                $c->id . ' names no hand-rolled alternative, so it is not a missable ability',
            );
        }
    }

    #[Test]
    public function ids_are_unique(): void
    {
        // Verify findings point at an id. Two capabilities answering to one id
        // means a finding sends the reader to the wrong mechanism.
        $ids = array_map(static fn (Capability $c): string => $c->id, self::capabilities());
        // Named, not merely detected: the point of failing is to be told which
        // id to go and change.
        $duplicates = array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));

        self::assertSame([], $duplicates, 'duplicate capability id: ' . implode(', ', $duplicates));
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
