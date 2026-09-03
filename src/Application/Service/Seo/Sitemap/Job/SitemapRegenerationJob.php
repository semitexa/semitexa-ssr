<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap\Job;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Tenancy\Context\TenantContext;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapLocator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapStoragePath;

/**
 * Regenerates sitemap.xml on a daily schedule.
 *
 * semitexa/scheduler is a hard dependency of this package (composer.json
 * `require`), so it is always present — the job is registered through
 * #[AsScheduledJob] discovery on every install. The previous note here implied
 * the dependency was optional, which it is not.
 */
#[AsService]
#[AsScheduledJob(
    key: 'ssr.sitemap_regeneration',
    cronExpression: 'env::SITEMAP_REGENERATION_CRON::0 3 * * *',
    overlapPolicy: 'skip',
    // Per tenant, because a sitemap is per site. A global run resolves no
    // tenant, so it writes var/sitemap/default — a file no domain is served
    // from — while every tenant's own sitemap.xml stays as it was, an empty
    // urlset, and the run still reports success.
    tenantMode: 'per_tenant',
)]
final class SitemapRegenerationJob implements ScheduledJobInterface
{
    #[InjectAsReadonly]
    protected SitemapGenerator $generator;

    public function handle(ScheduledJobContext $context): void
    {
        if (!isset($this->generator)) {
            return;
        }

        // The run carries its tenant; both the site's own address and the
        // directory its sitemap is served from come from that. Reading it off
        // the context rather than injecting it keeps this a plain service and
        // makes the one input the job actually has visible in one line.
        $tenantContext = $context->tenantId !== null && $context->tenantId !== ''
            ? TenantContext::fromResolution($context->tenantId, 'scheduler')
            : null;

        $baseUrl = AiSitemapLocator::originUrl(null, $tenantContext);
        $outputDir = SitemapStoragePath::generatedDirectory($tenantContext);

        $generationContext = new SitemapGenerationContext(
            baseUrl: $baseUrl,
            tenantContext: $tenantContext,
        );

        $this->generator->generateAndWrite($generationContext, $outputDir);
    }
}
