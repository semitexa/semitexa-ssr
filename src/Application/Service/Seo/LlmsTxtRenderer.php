<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Semitexa\Core\Environment;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;

final class LlmsTxtRenderer
{
    /** An index, not a dump: past this many pages the sitemap is the full list. */
    public const int MAX_PAGES = 200;

    /**
     * @param list<SitemapUrl> $pages the tenant's sitemap URLs, in sitemap order
     */
    public static function render(
        ?Request $request = null,
        ?TenantContextInterface $tenantContext = null,
        array $pages = [],
    ): string {
        $appName = self::siteName($tenantContext);

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
            ...self::pagesSection($pages),
            '## Scope',
            '',
            '- This file is advisory metadata for automated agents.',
            '- Project owners may override this fallback by providing llms.txt at the project root or in public/.',
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * The SITE being served, not the install. On a multi-tenant install
     * APP_NAME is one name for every domain, so each tenant's configured name
     * (TENANT_{ID}_NAME — the key semitexa/tenancy registers tenants from)
     * comes first.
     */
    private static function siteName(?TenantContextInterface $tenantContext): string
    {
        $tenantId = TenantContextAccess::tenantId($tenantContext);
        if ($tenantId !== null && $tenantId !== '') {
            $key = 'TENANT_' . strtoupper((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $tenantId)) . '_NAME';
            $tenantName = trim((string) (Environment::getEnvValue($key) ?? ''));
            if ($tenantName !== '') {
                return $tenantName;
            }
        }

        $appName = trim((string) (Environment::getEnvValue('APP_NAME') ?? ''));

        return $appName !== '' ? $appName : 'Semitexa site';
    }

    /**
     * @param list<SitemapUrl> $pages
     * @return list<string>
     */
    private static function pagesSection(array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        $lines = ['## Pages', ''];

        foreach (array_slice($pages, 0, self::MAX_PAGES) as $page) {
            $label = self::inline($page->title ?? '');
            if ($label === '') {
                $path = parse_url($page->loc, PHP_URL_PATH);
                $label = is_string($path) && $path !== '' ? $path : '/';
            }

            $line = '- [' . self::escapeLabel($label) . '](' . self::escapeUrl($page->loc) . ')';
            $description = self::inline($page->description ?? '');
            if ($description !== '') {
                $line .= ': ' . $description;
            }
            $lines[] = $line;
        }

        if (count($pages) > self::MAX_PAGES) {
            $lines[] = '';
            $lines[] = sprintf(
                '%d more pages are not listed here; the AI sitemap and sitemap.xml have the full list.',
                count($pages) - self::MAX_PAGES,
            );
        }

        $lines[] = '';

        return $lines;
    }

    /** One line of text: a title or description must not break the list. */
    private static function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function escapeLabel(string $label): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $label);
    }

    private static function escapeUrl(string $url): string
    {
        return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url);
    }
}
