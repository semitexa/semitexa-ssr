<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Semitexa\Core\Environment;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextInterface;

final class RobotsTxtRenderer
{
    /**
     * Every framework service endpoint (/_feed, /_auth, /__explorer, /__observatory,
     * /__ui, /__semitexa_kiss, /__semitexa_hug, /__trace, /__quality, ...) lives
     * under this prefix; one rule covers both `/_` and `/__`.
     */
    public const RESERVED_PREFIX = '/_';

    public static function render(?Request $request = null, ?TenantContextInterface $tenantContext = null): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            ...array_map(
                static fn (string $path): string => 'Disallow: ' . $path,
                self::disallowedPaths(),
            ),
            '',
            '# Semitexa crawler hints',
            '# LLMS: ' . AiSitemapLocator::originUrl($request, $tenantContext) . '/llms.txt',
            '# AI sitemap: ' . AiSitemapLocator::absoluteUrl($request, $tenantContext),
            '# HTML pages may expose a machine-readable alternate JSON document.',
            '# Try the same page with ?_format=json when you want page/meta/slot IRIs.',
            '# Slot documents are addressable with ?_format=json&_slot=<name>.',
            '# Deferred regions stay part of the same SSR page model.',
        ];

        $sitemap = trim((string) (Environment::getEnvValue('ROBOTS_SITEMAP_URL') ?? ''));
        if ($sitemap !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $sitemap;
        } else {
            $origin = AiSitemapLocator::originUrl($request, $tenantContext);
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $origin . '/sitemap.xml';
        }

        $host = trim((string) (Environment::getEnvValue('ROBOTS_HOST') ?? ''));
        if ($host !== '') {
            $lines[] = 'Host: ' . $host;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The reserved prefix first, then each comma-separated ROBOTS_DISALLOW entry:
     * trimmed, blanks skipped, a missing leading slash added, entries with
     * whitespace, '#' or control characters dropped, duplicates removed.
     *
     * @return list<string>
     */
    public static function disallowedPaths(): array
    {
        $paths = [self::RESERVED_PREFIX];

        $configured = (string) (Environment::getEnvValue('ROBOTS_DISALLOW') ?? '');
        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || preg_match('/[\s#\x00-\x1F\x7F]/', $entry) === 1) {
                continue;
            }
            if ($entry[0] !== '/') {
                $entry = '/' . $entry;
            }
            if (!in_array($entry, $paths, true)) {
                $paths[] = $entry;
            }
        }

        return $paths;
    }
}
