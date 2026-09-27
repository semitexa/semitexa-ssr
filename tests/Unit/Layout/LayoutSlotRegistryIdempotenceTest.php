<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;

/**
 * A second discovery run in one process (a rebuilt container) registers every
 * slot again. An identical declaration must not stack — a page would render
 * the block twice — while declarations that differ in any field still do.
 */
final class LayoutSlotRegistryIdempotenceTest extends TestCase
{
    private const HANDLE = 'slot-idempotence-probe';

    /** @var array<string, mixed> */
    private array $snapshot;

    protected function setUp(): void
    {
        // Process-global: snapshot and restore, never reset.
        $this->snapshot = $this->slots()->getValue();
    }

    protected function tearDown(): void
    {
        $this->slots()->setValue(null, $this->snapshot);
    }

    #[Test]
    public function an_identical_declaration_is_registered_once(): void
    {
        LayoutSlotRegistry::register(self::HANDLE, 'main', 'a.html.twig', ['x' => 1], 5, true);
        LayoutSlotRegistry::register(self::HANDLE, 'main', 'a.html.twig', ['x' => 1], 5, true);

        self::assertCount(1, LayoutSlotRegistry::getSlotsForHandle(self::HANDLE)['main']);
    }

    #[Test]
    public function declarations_that_differ_in_any_field_still_stack(): void
    {
        LayoutSlotRegistry::register(self::HANDLE, 'main', 'a.html.twig', ['x' => 1], 5, true);
        LayoutSlotRegistry::register(self::HANDLE, 'main', 'b.html.twig', ['x' => 1], 5, true);
        LayoutSlotRegistry::register(self::HANDLE, 'main', 'a.html.twig', ['x' => 2], 5, true);
        LayoutSlotRegistry::register(self::HANDLE, 'main', 'a.html.twig', ['x' => 1], 6, true);
        LayoutSlotRegistry::register(self::HANDLE, 'main', 'a.html.twig', ['x' => 1], 5, false);

        self::assertCount(5, LayoutSlotRegistry::getSlotsForHandle(self::HANDLE)['main']);
    }

    private function slots(): \ReflectionProperty
    {
        return new \ReflectionProperty(LayoutSlotRegistry::class, 'slots');
    }
}
