<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Domain\Model\FormDocumentScope;

/**
 * Collaborative Form Data · Phase 1 — the canonical document scope-key
 * formatter. Both the feed payload (subscribe side) and the inbound handler
 * (publish/touch side) format through this, so the format and its safe-alphabet
 * guards are pinned here.
 */
final class FormDocumentScopeTest extends TestCase
{
    #[Test]
    public function for_record_formats_the_three_segment_key(): void
    {
        self::assertSame('formdoc:article:0190a-uuid7', FormDocumentScope::forRecord('article', '0190a-uuid7'));
    }

    #[Test]
    public function for_draft_formats_the_two_segment_key(): void
    {
        self::assertSame('form:uci_4f8a', FormDocumentScope::forDraft('uci_4f8a'));
    }

    #[Test]
    public function a_record_key_round_trips_through_is_valid(): void
    {
        self::assertTrue(FormDocumentScope::isValid(FormDocumentScope::forRecord('lead_form', 'abc-123')));
        self::assertTrue(FormDocumentScope::isValid(FormDocumentScope::forDraft('inst_9')));
    }

    #[Test]
    public function the_key_carries_no_dot_so_it_cannot_be_mistaken_for_a_tenant_boundary(): void
    {
        // The channel delimiter is '.'; a scope segment must never contain one.
        self::assertStringNotContainsString('.', FormDocumentScope::forRecord('article', 'rec-1'));
    }

    #[Test]
    public function an_invalid_form_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormDocumentScope::forRecord('Article.With.Dots', 'rec-1');
    }

    #[Test]
    public function an_invalid_record_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormDocumentScope::forRecord('article', 'bad id with spaces');
    }

    #[Test]
    public function is_valid_rejects_unknown_prefixes_and_malformed_keys(): void
    {
        self::assertFalse(FormDocumentScope::isValid('grid:article:1'));
        self::assertFalse(FormDocumentScope::isValid('formdoc:article'));
        self::assertFalse(FormDocumentScope::isValid('formdoc:Article:1'));
        self::assertFalse(FormDocumentScope::isValid('form:'));
    }
}
