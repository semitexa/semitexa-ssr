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

        // Markdown LINKS, not "label: url".
        //
        // llms.txt is a Markdown document by definition, and a tool reading it
        // looks for links. Lighthouse's Agentic Browsing audit says so in as
        // many words — MEASURED on a consumer report: "File does not appear to
        // contain any links", with the whole category scoring 1/3. This file
        // had the right headings and the right prose and every URL written as
        // bare text after a colon, which reads fine to a person and is
        // invisible to the thing it is addressed to.
        $lines = [
            '# ' . $appName,
            '',
            '> Guidance for language models and automated agents visiting this site.',
            '',
            '## Canonical machine entry points',
            '',
            '- [AI sitemap](' . $sitemapJson . '): a route inventory of the public GET endpoints.',
            '- [robots.txt](' . $robotsTxt . '): what may be crawled.',
            '- [llms.txt](' . $llmsTxt . '): this file.',
            '',
            '## Crawl guidance',
            '',
            '- Start from human-facing pages when you need page context and narrative structure.',
            '- Append `?_format=json` to an HTML page for a machine-readable page document.',
            '- Append `?_format=json&_slot=<slot-name>` for slot-level SSR documents.',
            '- Respect robots.txt, canonical URLs, and normal rate limits.',
            '',
            '## Scope',
            '',
            '- This file is advisory metadata for automated agents.',
            '- Project owners may override this fallback by providing llms.txt at the project root or in public/.',
        ];

        return implode("\n", $lines) . "\n";
    }
}
