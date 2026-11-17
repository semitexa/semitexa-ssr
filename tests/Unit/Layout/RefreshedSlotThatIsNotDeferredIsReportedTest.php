<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Console\Command\LintDeferredSlotsCommand;
use Semitexa\Ssr\Application\Service\Layout\DeferralIntentAudit;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Domain\Model\DeferralIntentFinding;
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

    private mixed $snapshot = null;

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
    public function the_registry_names_the_refreshed_slots_that_are_not_deferred_and_no_other(): void
    {
        LayoutSlotRegistry::register(self::HANDLE, 'Ticker', 'ticker.html.twig', refreshInterval: 5, resourceClass: 'App\Slot\TickerSlot');
        LayoutSlotRegistry::register(self::HANDLE, 'clock', 'clock.html.twig', deferred: true, refreshInterval: 5);
        LayoutSlotRegistry::register(self::HANDLE, 'static', 'static.html.twig');

        $mine = array_values(array_filter(
            LayoutSlotRegistry::getRefreshedInlineSlots(),
            static fn (array $slot): bool => $slot['handle'] === self::HANDLE,
        ));

        self::assertSame([[
            'slot' => 'ticker',
            'handle' => self::HANDLE,
            'resourceClass' => 'App\Slot\TickerSlot',
            'refreshInterval' => 5,
        ]], $mine);
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

    private function slots(): \ReflectionProperty
    {
        return new \ReflectionProperty(LayoutSlotRegistry::class, 'slots');
    }
}
