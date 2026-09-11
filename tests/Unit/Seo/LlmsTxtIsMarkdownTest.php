<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Seo\LlmsTxtRenderer;

/**
 * llms.txt is a Markdown document, and a tool reading it looks for links.
 *
 * MEASURED on a consumer's PageSpeed report: the Agentic Browsing category
 * scored 1/3 and stated the error verbatim — "File does not appear to contain
 * any links." The fallback this class renders had the right headings and the
 * right prose, and wrote every URL as bare text after a colon: `- LLMS: https://…`.
 * That reads fine to a person and is invisible to the thing the file is
 * addressed to, which is the whole audience.
 *
 * Every Semitexa site that does not override llms.txt ships THIS file, so the
 * defect was not one site's — it was the default.
 */
final class LlmsTxtIsMarkdownTest extends TestCase
{
    private function rendered(): string
    {
        return LlmsTxtRenderer::render();
    }

    /** The audit's stated minimum: a Markdown file with at least one H1. */
    #[Test]
    public function it_opens_with_a_level_one_heading(): void
    {
        self::assertMatchesRegularExpression('/\A# \S/', $this->rendered());
    }

    /** The other half of the audit, and the half that was missing. */
    #[Test]
    public function it_contains_markdown_links(): void
    {
        preg_match_all('/- \[[^\]]+\]\((https?:\/\/[^)]+)\)/', $this->rendered(), $m);

        self::assertGreaterThanOrEqual(
            3,
            count($m[0]),
            'The entry points are what an agent is here to find; they must be links, not labelled text.',
        );
    }

    /**
     * The exact shape that failed. A bullet whose URL sits bare after a colon
     * is the defect this file was rewritten out of, so it must not come back
     * anywhere in the document.
     */
    #[Test]
    public function no_bullet_carries_a_bare_url_after_a_label(): void
    {
        $offenders = [];

        foreach (explode("\n", $this->rendered()) as $line) {
            if (preg_match('/^- [^\[]*: *https?:\/\//', $line) === 1) {
                $offenders[] = $line;
            }
        }

        self::assertSame([], $offenders, 'write these as [title](url), which is what the audit counts');
    }

    /** Each canonical entry point is reachable, named, and described. */
    #[Test]
    public function the_three_entry_points_are_all_present_as_links(): void
    {
        $out = $this->rendered();

        foreach (['sitemap.json', 'robots.txt', 'llms.txt'] as $target) {
            self::assertMatchesRegularExpression(
                '/- \[[^\]]+\]\([^)]*' . preg_quote($target, '/') . '\):/',
                $out,
                $target . ' must appear as a described markdown link',
            );
        }
    }

    /** A blockquote summary — what llmstxt.org asks for right after the title. */
    #[Test]
    public function it_carries_a_summary_line(): void
    {
        self::assertMatchesRegularExpression('/^> \S/m', $this->rendered());
    }
}
