<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Layout\DeferralIntentAudit;
use Semitexa\Ssr\Domain\Model\DeferralIntentFinding;
use Semitexa\Ssr\Domain\Model\DeferralIntentKind;

/**
 * Declared deferred, and never deferred — the state of an entire production
 * console on 2026-09-16: 50 slot resources carrying `deferred: true`, not one
 * page calling `layout_slot_deferred`, and a green verify the whole time.
 *
 * The audit is fed plain data here on purpose. What it reports is a question
 * about two texts, and answering it should not need discovery, a Twig
 * environment or a filesystem.
 */
final class DeferralIntentAuditTest extends TestCase
{
    private DeferralIntentAudit $audit;

    protected function setUp(): void
    {
        $this->audit = new DeferralIntentAudit();
    }

    #[Test]
    public function aDeclarationNoTemplateHonoursIsReported(): void
    {
        $findings = $this->audit->audit(
            ['sidebar' => 'App\Slot\SidebarSlot'],
            ['page.html.twig' => '{{ layout_slot(\'sidebar\') }}'],
        );

        self::assertCount(1, $findings);
        self::assertSame(DeferralIntentKind::DeclaredButNeverDeferred, $findings[0]->kind);
        self::assertSame('sidebar', $findings[0]->slot);
        self::assertSame('App\Slot\SidebarSlot', $findings[0]->origin);
        self::assertStringContainsString('renders inline', $findings[0]->consequence);
    }

    #[Test]
    public function bothHalvesAgreeingIsSilence(): void
    {
        $findings = $this->audit->audit(
            ['sidebar' => 'App\Slot\SidebarSlot'],
            ['page.html.twig' => '{{ layout_slot_deferred(\'sidebar\') }}'],
        );

        self::assertSame([], $findings);
    }

    #[Test]
    public function theMirrorCaseIsReportedToo(): void
    {
        // layout_slot_deferred on an undeclared slot does not fail — it falls
        // back to rendering inline, so the author gets no placeholder and no
        // word about why.
        $findings = $this->audit->audit(
            [],
            ['page.html.twig' => '{{ layout_slot_deferred(\'ghost\') }}'],
        );

        self::assertCount(1, $findings);
        self::assertSame(DeferralIntentKind::DeferredCallWithoutDeclaration, $findings[0]->kind);
        self::assertSame('page.html.twig:1', $findings[0]->origin);
    }

    #[Test]
    public function caseNeverDecidesWhetherTwoHalvesMatch(): void
    {
        // The registry lowercases every key it stores, so a declaration and a
        // call differing only in case are the same slot at runtime. An audit
        // that disagreed with the registry would invent findings.
        $findings = $this->audit->audit(
            ['SideBar' => 'App\Slot\SidebarSlot'],
            ['page.html.twig' => '{{ layout_slot_deferred("sidebar") }}'],
        );

        self::assertSame([], $findings);
    }

    #[Test]
    public function aNameInsideADefaultFilterStillCounts(): void
    {
        // Real shape, from SsrPolygon: the slot id arrives as a variable with
        // a literal fallback. Matching only a literal in first position read
        // this as no call at all.
        $findings = $this->audit->audit(
            ['ssrp_nested_inner' => 'App\Slot\NestedInnerSlot'],
            ['block.html.twig' => "{{ layout_slot_deferred(inner_slot_id|default('ssrp_nested_inner')) }}"],
        );

        self::assertSame([], $findings);
    }

    #[Test]
    public function documentationIsNotACall(): void
    {
        // A page that SHOWS the call inside {% verbatim %} defers nothing. The
        // first run of this audit named exactly such a snippet as the origin
        // of a finding that belonged to another file.
        $source = <<<'TWIG'
        {% verbatim %}{{ layout_slot_deferred('shown_not_called') }}{% endverbatim %}
        {# {{ layout_slot_deferred('commented_out') }} #}
        TWIG;

        $findings = $this->audit->audit([], ['docs.html.twig' => $source]);

        self::assertSame([], $findings);
    }

    #[Test]
    public function theLineNumberSurvivesTheBlanking(): void
    {
        $source = "{% verbatim %}{{ layout_slot_deferred('shown') }}{% endverbatim %}\n\n{{ layout_slot_deferred('real') }}";

        $findings = $this->audit->audit([], ['page.html.twig' => $source]);

        self::assertSame(['real'], array_map(static fn ($f): string => $f->slot, $findings));
        self::assertSame('page.html.twig:3', $findings[0]->origin);
    }

    #[Test]
    public function aSlotDeferredByOneTemplateIsSatisfiedForAll(): void
    {
        // Matched by NAME, not by page handle. Stated as a test because it is
        // the audit's known blind spot: a name used by two pages with
        // different intents reads as satisfied by either.
        $findings = $this->audit->audit(
            ['shared' => 'App\Slot\SharedSlot'],
            [
                'a.html.twig' => '{{ layout_slot(\'shared\') }}',
                'b.html.twig' => '{{ layout_slot_deferred(\'shared\') }}',
            ],
        );

        self::assertSame([], $findings);
    }

    #[Test]
    public function findingsArriveGroupedAndOrdered(): void
    {
        $findings = $this->audit->audit(
            ['zulu' => 'App\Slot\ZuluSlot', 'alpha' => 'App\Slot\AlphaSlot'],
            ['page.html.twig' => '{{ layout_slot_deferred(\'ghost\') }}'],
        );

        self::assertSame(
            ['declared_but_never_deferred:alpha', 'declared_but_never_deferred:zulu', 'deferred_call_without_declaration:ghost'],
            array_map(static fn (DeferralIntentFinding $f): string => $f->kind->value . ':' . $f->slot, $findings),
        );
    }

    #[Test]
    public function aTreeWithNoSlotsAtAllIsClean(): void
    {
        self::assertSame([], $this->audit->audit([], ['page.html.twig' => '<p>nothing here</p>']));
    }
}
