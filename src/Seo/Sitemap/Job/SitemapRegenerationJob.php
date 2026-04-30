<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo\Sitemap\Job;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;
use Semitexa\Ssr\Seo\AiSitemapLocator;
use Semitexa\Ssr\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Seo\Sitemap\SitemapStoragePath;

/**
 * Regenerates sitemap.xml on a daily schedule.
 *
 * Requires the semitexa/scheduler package. If the scheduler is not installed,
 * this class is never instantiated (auto-discovery skips it).
 */
#[AsScheduledJob(
    key: 'ssr.sitemap_regeneration',
    cronExpression: '0 3 * * *',
    overlapPolicy: 'skip',
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

        $baseUrl = AiSitemapLocator::originUrl();
        $outputDir = SitemapStoragePath::generatedDirectory();

        $generationContext = new SitemapGenerationContext(baseUrl: $baseUrl);

        $this->generator->generateAndWrite($generationContext, $outputDir);
    }
}
