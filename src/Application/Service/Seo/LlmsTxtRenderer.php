<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Semitexa\Core\Environment;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextInterface;

final class LlmsTxtRenderer
{
    public static function render(?Request $request = null, ?TenantContextInterface $tenantContext = null): string
    {
        $appName = trim((string) (Environment::getEnvValue('APP_NAME') ?? ''));
        if ($appName === '') {
            $appName = 'Semitexa site';
        }

        $origin = AiSitemapLocator::originUrl($request, $tenantContext);
        $sitemapJson = AiSitemapLocator::absoluteUrl($request, $tenantContext);
        $robotsTxt = $origin . '/robots.txt';
        $llmsTxt = $origin . '/llms.txt';

        $lines = [
            '# ' . $appName,
            '',
            '> Guidance for language models and automated agents visiting this site.',
            '',
            '## Canonical machine entry points',
            '- LLMS: ' . $llmsTxt,
            '- AI sitemap: ' . $sitemapJson,
            '- Robots: ' . $robotsTxt,
            '',
            '## Crawl guidance',
            '- Start from human-facing pages when you need page context and narrative structure.',
            '- Use /sitemap.json for a route inventory of public GET endpoints.',
            '- For HTML pages, prefer ?_format=json when you need a machine-readable page document.',
            '- Use ?_format=json&_slot=<slot-name> when you need slot-level SSR documents.',
            '- Respect robots.txt, canonical URLs, and normal rate limits.',
            '',
            '## Scope',
            '- This file is advisory metadata for automated agents.',
            '- Project owners may override this fallback by providing llms.txt at the project root or in public/.',
        ];

        return implode("\n", $lines) . "\n";
    }
}
