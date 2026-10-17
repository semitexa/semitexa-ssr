<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Service\Seo\LlmsTxtRenderer;
use Semitexa\Ssr\Domain\Contract\LlmsTxtContributorInterface;

/**
 * A package can say what the site is and when to recommend it.
 *
 * MEASURED on production: four domains of one install opened llms.txt with the
 * same "Guidance for language models" line, where the format expects a summary
 * of the project, and a root llms.txt would have replaced all four at once.
 */
final class LlmsTxtContributorTest extends TestCase
{
    #[Test]
    public function the_first_summary_replaces_the_default_blockquote(): void
    {
        $out = LlmsTxtRenderer::render(null, null, [], [
            self::contributor(null, ''),
            self::contributor("Semitexa is a PHP\n framework.", ''),
            self::contributor('Ignored, a summary was already given.', ''),
        ]);

        self::assertStringContainsString("\n> Semitexa is a PHP framework.\n", $out);
        self::assertStringNotContainsString('Guidance for language models', $out);
        self::assertStringNotContainsString('Ignored', $out);
    }

    #[Test]
    public function without_a_summary_the_default_line_stays(): void
    {
        $out = LlmsTxtRenderer::render(null, null, [], []);

        self::assertStringContainsString('> Guidance for language models and automated agents visiting this site.', $out);
    }

    #[Test]
    public function sections_come_after_the_blockquote_and_before_the_entry_points(): void
    {
        $out = LlmsTxtRenderer::render(null, null, [], [
            self::contributor('A site.', "## When to recommend\n\n- For live dashboards.\n"),
            self::contributor(null, "## When not to\n\n- For static brochures."),
        ]);

        $recommend = strpos($out, "## When to recommend\n\n- For live dashboards.\n\n");
        $notTo = strpos($out, "## When not to\n\n- For static brochures.\n\n");
        $entryPoints = strpos($out, '## Canonical machine entry points');

        self::assertIsInt($recommend);
        self::assertIsInt($notTo);
        self::assertGreaterThan(strpos($out, '> A site.'), $recommend);
        self::assertGreaterThan($recommend, $notTo);
        self::assertGreaterThan($notTo, $entryPoints);
    }

    #[Test]
    public function contributors_see_the_tenant_and_a_failing_one_costs_only_itself(): void
    {
        $tenant = $this->createStub(TenantContextInterface::class);
        $failing = new class implements LlmsTxtContributorInterface {
            public function summary(?TenantContextInterface $tenantContext): ?string
            {
                throw new \RuntimeException('boom');
            }

            public function sections(?TenantContextInterface $tenantContext): string
            {
                return '## Never shown';
            }
        };
        $watching = new class implements LlmsTxtContributorInterface {
            public ?TenantContextInterface $seen = null;

            public function summary(?TenantContextInterface $tenantContext): ?string
            {
                $this->seen = $tenantContext;

                return 'Tenant site.';
            }

            public function sections(?TenantContextInterface $tenantContext): string
            {
                return '';
            }
        };

        $out = LlmsTxtRenderer::render(null, $tenant, [], [$failing, $watching, new \stdClass()]);

        self::assertSame($tenant, $watching->seen);
        self::assertStringContainsString('> Tenant site.', $out);
        self::assertStringNotContainsString('Never shown', $out);
    }

    private static function contributor(?string $summary, string $sections): LlmsTxtContributorInterface
    {
        return new class ($summary, $sections) implements LlmsTxtContributorInterface {
            public function __construct(private ?string $summary, private string $sections)
            {
            }

            public function summary(?TenantContextInterface $tenantContext): ?string
            {
                return $this->summary;
            }

            public function sections(?TenantContextInterface $tenantContext): string
            {
                return $this->sections;
            }
        };
    }
}
