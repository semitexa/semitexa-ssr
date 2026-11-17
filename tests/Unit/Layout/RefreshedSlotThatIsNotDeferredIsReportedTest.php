<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Console\Command\LintDeferredSlotsCommand;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionCatalog;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Layout\DeferralIntentAudit;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Domain\Model\DeferralIntentFinding;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * tk-refreshed-slot-not-deferred-is-silent: live push starts from a page's
 * deferred slots, so a slot with refreshInterval and no `deferred: true` renders
 * inline once and is never pushed to. lint:deferred-slots saw deferred slots
 * only, so it said nothing; the docs said it, and a reader of the resource
 * believed the region was live.
 */
final class RefreshedSlotThatIsNotDeferredIsReportedTest extends TestCase
{
    private const HANDLE = 'refreshed-inline-probe';

    /** @var array<string, mixed> property => what it held before the test */
    private array $snapshot = [];

    protected function setUp(): void
    {
        // Process-global: snapshot and restore, never reset. The lint itself sets the
        // template and extension catalogs' dependencies, so those pointers are kept too.
        foreach ($this->globals() as $key => $property) {
            $this->snapshot[$key] = $property->getValue();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->globals() as $key => $property) {
            $property->setValue(null, $this->snapshot[$key]);
        }
    }

    #[Test]
    public function the_registry_names_the_refreshed_slots_that_are_not_deferred_and_no_other(): void
    {
        LayoutSlotRegistry::register(self::HANDLE, 'Ticker', 'ticker.html.twig', refreshInterval: 5, resourceClass: 'App\Slot\TickerSlot');
        LayoutSlotRegistry::register(self::HANDLE, 'clock', 'clock.html.twig', deferred: true, refreshInterval: 5);
        LayoutSlotRegistry::register(self::HANDLE, 'static', 'static.html.twig');

        self::assertSame([[
            'slot' => 'ticker',
            'handle' => self::HANDLE,
            'resourceClass' => 'App\Slot\TickerSlot',
            'refreshInterval' => 5,
        ]], $this->mine(self::HANDLE));
    }

    /**
     * A numeric name is an int array key. Handed on as one, it broke the strict
     * audit (strtolower(int)) and the deferred slot descriptors (string slotId),
     * on the page render path too (review, ssr#185).
     */
    #[Test]
    public function a_numeric_slot_or_handle_name_stays_a_string(): void
    {
        LayoutSlotRegistry::register(self::HANDLE, '456', 'inline.html.twig', refreshInterval: 5);
        LayoutSlotRegistry::register(self::HANDLE, '123', 'deferred.html.twig', deferred: true);
        LayoutSlotRegistry::register('2024', 'feed', 'feed.html.twig', deferred: true);
        LayoutSlotRegistry::register('2024', '789', 'year.html.twig', refreshInterval: 30);

        self::assertSame(
            [['slot' => '456', 'handle' => self::HANDLE, 'resourceClass' => null, 'refreshInterval' => 5]],
            $this->mine(self::HANDLE),
        );
        self::assertSame(
            [['slot' => '789', 'handle' => '2024', 'resourceClass' => null, 'refreshInterval' => 30]],
            $this->mine('2024'),
        );
        self::assertSame(['123'], array_map(
            static fn (DeferredSlotDefinition $slot): string => $slot->slotId,
            LayoutSlotRegistry::getDeferredSlots(self::HANDLE),
        ));
        $deferred = array_values(array_filter(
            LayoutSlotRegistry::getAllDeferredSlots(),
            static fn (DeferredSlotDefinition $slot): bool => in_array($slot->pageHandle, [self::HANDLE, '2024'], true),
        ));
        self::assertSame(
            [self::HANDLE . ':123', '2024:feed'],
            array_map(static fn (DeferredSlotDefinition $slot): string => $slot->pageHandle . ':' . $slot->slotId, $deferred),
        );
        self::assertSame(['456', '789'], array_map(
            static fn (DeferralIntentFinding $finding): string => $finding->slot,
            (new DeferralIntentAudit())->refreshedButNotDeferred([...$this->mine(self::HANDLE), ...$this->mine('2024')]),
        ));
    }

    #[Test]
    public function the_audit_says_what_happens_and_what_to_do(): void
    {
        $findings = (new DeferralIntentAudit())->refreshedButNotDeferred([
            ['slot' => 'Ticker', 'handle' => 'dashboard', 'resourceClass' => 'App\Slot\TickerSlot', 'refreshInterval' => 5],
            ['slot' => 'ticker', 'handle' => 'overview', 'resourceClass' => null, 'refreshInterval' => 5],
            ['slot' => 'clock', 'handle' => 'overview', 'resourceClass' => null, 'refreshInterval' => 30],
        ]);

        self::assertSame([
            [
                'kind' => 'refreshed_but_not_deferred',
                'slot' => 'clock',
                'origin' => 'handle overview',
                'consequence' => 'renders inline once and is never refreshed: refreshInterval is 30 s, but only a deferred slot is pushed to. '
                    . 'Declare it deferred: true and render it with layout_slot_deferred(), or drop refreshInterval.',
            ],
            [
                'kind' => 'refreshed_but_not_deferred',
                'slot' => 'ticker',
                'origin' => 'App\Slot\TickerSlot',
                'consequence' => 'renders inline once and is never refreshed: refreshInterval is 5 s, but only a deferred slot is pushed to. '
                    . 'Declare it deferred: true and render it with layout_slot_deferred(), or drop refreshInterval.',
            ],
        ], array_map(static fn (DeferralIntentFinding $f): array => $f->toArray(), $findings));
    }

    #[Test]
    public function the_lint_reports_it(): void
    {
        LayoutSlotRegistry::register(self::HANDLE, 'probe-ticker', 'ticker.html.twig', refreshInterval: 5, resourceClass: 'App\Slot\ProbeTickerSlot');
        // The lint wires its own dependencies into these catalogs: let it wire temporary ones.
        ModuleTemplateRegistry::setCatalog(new ModuleTemplateCatalog());
        TwigExtensionRegistry::setCatalog(new TwigExtensionCatalog());

        $tester = new CommandTester(new LintDeferredSlotsCommand());
        $tester->execute(['--json' => true]);

        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['findings'] ?? null);
        $mine = array_values(array_filter(
            $report['findings'],
            static fn (mixed $finding): bool => is_array($finding) && ($finding['slot'] ?? null) === 'probe-ticker',
        ));
        self::assertSame([[
            'kind' => 'refreshed_but_not_deferred',
            'slot' => 'probe-ticker',
            'origin' => 'App\Slot\ProbeTickerSlot',
            'consequence' => 'renders inline once and is never refreshed: refreshInterval is 5 s, but only a deferred slot is pushed to. '
                . 'Declare it deferred: true and render it with layout_slot_deferred(), or drop refreshInterval.',
        ]], $mine);
        self::assertFalse($report['clean']);
    }

    /** @return list<array{slot: string, handle: string, resourceClass: ?string, refreshInterval: int}> */
    private function mine(string $handle): array
    {
        return array_values(array_filter(
            LayoutSlotRegistry::getRefreshedInlineSlots(),
            static fn (array $slot): bool => $slot['handle'] === $handle,
        ));
    }

    /** @return array<string, \ReflectionProperty> */
    private function globals(): array
    {
        return [
            'slots' => new \ReflectionProperty(LayoutSlotRegistry::class, 'slots'),
            'templates' => new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'),
            'extensions' => new \ReflectionProperty(TwigExtensionRegistry::class, 'catalog'),
        ];
    }
}
