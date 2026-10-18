<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Readiness;

/**
 * Reports a site's agent-readiness from what the site SERVES, never from its
 * database: an agent reads responses, so a value stored but not rendered does
 * not count.
 *
 * The signal set was decided on 2026-09-17 (tk-readiness-signals-decision):
 * per site llms.txt (and whether its links resolve), robots.txt and the
 * sitemap; per page structured data, canonical, written-vs-defaulted title and
 * description, and whether it renders without JavaScript. No external rubric
 * is modelled and no score is produced.
 *
 * Every request is anonymous. A page behind auth is reported as unreadable,
 * which is exactly what it is to an agent. A tenant is audited through its own
 * origin — the host selects the tenant, as it does for any visitor.
 */
final class SiteReadinessAudit
{
    /** @var \Closure(string): ProbeResponse */
    private readonly \Closure $fetch;

    /** @var array<string, ProbeResponse> */
    private array $responses = [];

    /**
     * @param \Closure(string): ProbeResponse $fetch plain GET of an absolute URL
     */
    public function __construct(\Closure $fetch)
    {
        $this->fetch = $fetch;
    }

    /**
     * @param list<string> $pages  paths to audit; empty = the home page plus a sitemap sample
     * @param int          $sample how many sitemap entries / llms.txt links to resolve
     * @return list<ReadinessSignal>
     */
    public function run(string $origin, array $pages = [], int $sample = 10): array
    {
        $origin = rtrim($origin, '/');
        $this->responses = [];
        $signals = [];

        $signals[] = $this->llmsTxt($origin, $sample);
        $signals[] = $this->robotsTxt($origin);
        [$sitemapSignal, $sitemapPaths] = $this->sitemap($origin, $sample);
        $signals[] = $sitemapSignal;

        if ($pages === []) {
            $pages = array_values(array_unique(array_merge(['/'], $sitemapPaths)));
        }

        $home = $this->get($origin, '/');
        $homeHead = $home->status === 200 && $home->isHtml() ? self::head($home->body) : null;

        foreach ($pages as $path) {
            foreach ($this->page($origin, $path, $homeHead) as $signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    private function llmsTxt(string $origin, int $sample): ReadinessSignal
    {
        $by = 'GET /llms.txt';
        $response = $this->get($origin, '/llms.txt');
        if ($response->status !== 200) {
            return new ReadinessSignal('site', 'llms.txt', ReadinessSignal::STATE_ABSENT, self::answered($response), $by);
        }

        preg_match_all('#https?://[^\s)\]>"\']+#i', $response->body, $m);
        $links = array_values(array_unique($m[0]));
        if ($links === []) {
            return new ReadinessSignal('site', 'llms.txt', ReadinessSignal::STATE_PARTIAL, 'served, but links nowhere', $by);
        }

        $checked = array_slice($links, 0, $sample);
        $broken = [];
        foreach ($checked as $link) {
            $path = self::sameOriginPath($origin, $link);
            $answer = $path !== null ? $this->get($origin, $path) : ($this->fetch)($link);
            if ($answer->status < 200 || $answer->status >= 400) {
                $broken[] = $link . ' (' . self::answered($answer) . ')';
            }
        }

        $detail = sprintf('%d link(s), %d checked, %d resolve', count($links), count($checked), count($checked) - count($broken))
            . self::foreignNote($origin, $checked);
        if ($broken !== []) {
            return new ReadinessSignal('site', 'llms.txt', ReadinessSignal::STATE_PARTIAL, $detail . '; broken: ' . implode(', ', $broken), $by . ' + GET each link');
        }

        return new ReadinessSignal('site', 'llms.txt', ReadinessSignal::STATE_PRESENT, $detail, $by . ' + GET each link');
    }

    private function robotsTxt(string $origin): ReadinessSignal
    {
        $by = 'GET /robots.txt';
        $response = $this->get($origin, '/robots.txt');
        if ($response->status !== 200) {
            return new ReadinessSignal('site', 'robots.txt', ReadinessSignal::STATE_ABSENT, self::answered($response), $by);
        }

        // Only the `User-agent: *` group decides what an unnamed agent may read.
        // Consecutive User-agent lines open ONE group (RFC 9309), so `*` named
        // next to another agent still owns the rules that follow.
        $inStarGroup = false;
        $previousWasAgent = false;
        $blocksRoot = false;
        $sitemaps = 0;
        foreach (preg_split('/\R/', $response->body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (stripos($line, 'user-agent:') === 0) {
                $isStar = trim(substr($line, 11)) === '*';
                $inStarGroup = $previousWasAgent ? ($inStarGroup || $isStar) : $isStar;
                $previousWasAgent = true;
                continue;
            }
            if ($line !== '') {
                $previousWasAgent = false;
            }
            if ($inStarGroup && stripos($line, 'disallow:') === 0 && trim(substr($line, 9)) === '/') {
                $blocksRoot = true;
            } elseif (stripos($line, 'sitemap:') === 0) {
                $sitemaps++;
            }
        }

        $detail = sprintf('%s; %d Sitemap line(s)', $blocksRoot ? 'User-agent * is disallowed from /' : 'User-agent * may read /', $sitemaps);

        return new ReadinessSignal(
            'site',
            'robots.txt',
            $blocksRoot ? ReadinessSignal::STATE_PARTIAL : ReadinessSignal::STATE_PRESENT,
            $detail,
            $by,
        );
    }

    /**
     * @return array{ReadinessSignal, list<string>} the signal and same-origin paths of the sampled entries
     */
    private function sitemap(string $origin, int $sample): array
    {
        $by = 'GET /sitemap.xml';
        $response = $this->get($origin, '/sitemap.xml');
        if ($response->status !== 200) {
            return [new ReadinessSignal('site', 'sitemap', ReadinessSignal::STATE_ABSENT, self::answered($response), $by), []];
        }

        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $response->body, $m);
        // An index lists child sitemaps, not pages: auditing those as pages
        // would report XML as "not HTML" and never reach a real page.
        if (preg_match('#<sitemapindex\b#i', $response->body) === 1) {
            $detail = sprintf('sitemap index of %d child sitemap(s); child sitemaps not sampled', count(array_unique($m[1])));

            return [new ReadinessSignal('site', 'sitemap', ReadinessSignal::STATE_PARTIAL, $detail, $by), []];
        }
        $entries = array_values(array_unique(array_map(
            static fn (string $loc): string => html_entity_decode($loc, ENT_QUOTES | ENT_XML1),
            $m[1],
        )));
        if ($entries === []) {
            return [new ReadinessSignal('site', 'sitemap', ReadinessSignal::STATE_PARTIAL, 'served, but lists no <loc> entries', $by), []];
        }

        $paths = [];
        $broken = [];
        $checked = array_slice($entries, 0, $sample);
        foreach ($checked as $loc) {
            $path = self::sameOriginPath($origin, $loc);
            $answer = $path !== null ? $this->get($origin, $path) : ($this->fetch)($loc);
            if ($answer->status !== 200) {
                $broken[] = $loc . ' (' . self::answered($answer) . ')';
            } elseif ($path !== null) {
                $paths[] = $path;
            }
        }

        $detail = sprintf('%d entr%s, %d checked, %d answer 200', count($entries), count($entries) === 1 ? 'y' : 'ies', count($checked), count($checked) - count($broken))
            . self::foreignNote($origin, $checked);
        if ($broken !== []) {
            $detail .= '; not answering: ' . implode(', ', $broken);
        }

        return [
            new ReadinessSignal(
                'site',
                'sitemap',
                $broken === [] ? ReadinessSignal::STATE_PRESENT : ReadinessSignal::STATE_PARTIAL,
                $detail,
                $by . ' + GET each sampled entry',
            ),
            $paths,
        ];
    }

    /**
     * @param array{title: string, description: string}|null $homeHead
     * @return list<ReadinessSignal>
     */
    private function page(string $origin, string $path, ?array $homeHead): array
    {
        $by = 'GET ' . $path . ' (Accept: */*)';
        $response = $this->get($origin, $path);

        if ($response->status !== 200) {
            $detail = self::answered($response);
            if ($response->status === 401 || $response->status === 403) {
                $detail .= ': an agent without a session cannot read it';
            }

            return [new ReadinessSignal($path, 'response', ReadinessSignal::STATE_UNREADABLE, $detail, $by)];
        }

        // What a plain GET returns IS the no-JavaScript answer: a page route
        // that negotiates JSON for Accept: */* renders nothing for an agent.
        if (!$response->isHtml()) {
            return [new ReadinessSignal(
                $path,
                'renders without JavaScript',
                ReadinessSignal::STATE_ABSENT,
                'answered ' . ($response->contentType !== '' ? $response->contentType : 'with no content type') . ', not HTML',
                $by,
            )];
        }

        $html = $response->body;
        $head = self::head($html);
        $isHome = $path === '/';

        return [
            $this->structuredData($path, $html, $by),
            self::canonical($path, $html, $by),
            self::headField($path, 'title', $head['title'], $isHome ? null : $homeHead['title'] ?? null, $by),
            self::headField($path, 'description', $head['description'], $isHome ? null : $homeHead['description'] ?? null, $by),
            self::textWithoutJs($path, $html, $by),
        ];
    }

    private function structuredData(string $path, string $html, string $by): ReadinessSignal
    {
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m);
        if ($m[1] === []) {
            return new ReadinessSignal($path, 'structured data', ReadinessSignal::STATE_ABSENT, 'no application/ld+json block', $by);
        }

        $types = [];
        $invalid = 0;
        foreach ($m[1] as $block) {
            $decoded = json_decode(trim($block), true);
            if (!is_array($decoded)) {
                $invalid++;
                continue;
            }
            foreach (self::jsonLdTypes($decoded) as $type) {
                $types[$type] = true;
            }
        }

        $detail = sprintf('%d block(s)', count($m[1]));
        if ($types !== []) {
            $detail .= ': ' . implode(', ', array_keys($types));
        }
        if ($invalid > 0) {
            return new ReadinessSignal($path, 'structured data', ReadinessSignal::STATE_INVALID, $detail . sprintf('; %d do not parse as JSON', $invalid), $by);
        }

        return new ReadinessSignal($path, 'structured data', ReadinessSignal::STATE_PRESENT, $detail, $by);
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private static function jsonLdTypes(array $node): array
    {
        $types = [];
        if (isset($node['@type']) && is_string($node['@type'])) {
            $types[] = $node['@type'];
        }
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                if (is_array($child)) {
                    $types = array_merge($types, self::jsonLdTypes($child));
                }
            }
        }
        if (array_is_list($node)) {
            foreach ($node as $child) {
                if (is_array($child)) {
                    $types = array_merge($types, self::jsonLdTypes($child));
                }
            }
        }

        return $types;
    }

    private static function canonical(string $path, string $html, string $by): ReadinessSignal
    {
        foreach (self::tags($html, 'link') as $attributes) {
            if (strtolower($attributes['rel'] ?? '') === 'canonical' && ($attributes['href'] ?? '') !== '') {
                return new ReadinessSignal($path, 'canonical', ReadinessSignal::STATE_PRESENT, $attributes['href'], $by);
            }
        }

        return new ReadinessSignal($path, 'canonical', ReadinessSignal::STATE_ABSENT, 'no <link rel="canonical">', $by);
    }

    private static function headField(string $path, string $name, string $value, ?string $homeValue, string $by): ReadinessSignal
    {
        if ($value === '') {
            return new ReadinessSignal($path, $name, ReadinessSignal::STATE_ABSENT, 'empty or missing', $by);
        }
        // Read from responses alone, "defaulted" can only mean "the same as
        // the home page": a page that never set its own value inherits it.
        if ($homeValue !== null && $value === $homeValue) {
            return new ReadinessSignal($path, $name, ReadinessSignal::STATE_DEFAULTED, 'same as the home page: "' . $value . '"', $by . ' + GET /');
        }

        return new ReadinessSignal($path, $name, ReadinessSignal::STATE_PRESENT, '"' . $value . '"', $by);
    }

    private static function textWithoutJs(string $path, string $html, string $by): ReadinessSignal
    {
        $body = preg_match('#<body[^>]*>(.*)</body>#is', $html, $m) === 1 ? $m[1] : $html;
        $body = (string) preg_replace('#<(script|style|template|noscript)\b[^>]*>.*?</\1>#is', ' ', $body);
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        if ($words === 0) {
            return new ReadinessSignal($path, 'renders without JavaScript', ReadinessSignal::STATE_ABSENT, 'the HTML body carries no text before scripts run', $by);
        }

        return new ReadinessSignal($path, 'renders without JavaScript', ReadinessSignal::STATE_PRESENT, sprintf('%d word(s) of text in the served HTML', $words), $by);
    }

    /**
     * @return array{title: string, description: string}
     */
    private static function head(string $html): array
    {
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1
            ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5))
            : '';

        $description = '';
        foreach (self::tags($html, 'meta') as $attributes) {
            if (strtolower($attributes['name'] ?? '') === 'description') {
                $description = trim($attributes['content'] ?? '');
                break;
            }
        }

        return ['title' => $title, 'description' => $description];
    }

    /**
     * @return list<array<string, string>> attributes of every <$name> tag, keys lowercased
     */
    private static function tags(string $html, string $name): array
    {
        preg_match_all('#<' . $name . '\b([^>]*)>#i', $html, $m);
        $tags = [];
        foreach ($m[1] as $raw) {
            preg_match_all('#([^\s"\'>/=]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#', $raw, $a, PREG_SET_ORDER);
            $attributes = [];
            foreach ($a as $pair) {
                // 2 = double-quoted, 3 = single-quoted, 4 = unquoted (minifiers
                // drop quotes); PCRE drops trailing unmatched groups, so the
                // highest group present is the one that matched.
                $attributes[strtolower($pair[1])] = html_entity_decode($pair[4] ?? $pair[3] ?? $pair[2] ?? '', ENT_QUOTES | ENT_HTML5);
            }
            $tags[] = $attributes;
        }

        return $tags;
    }

    private static function sameOriginPath(string $origin, string $url): ?string
    {
        if (!str_starts_with($url, $origin . '/') && $url !== $origin) {
            return null;
        }
        $path = substr($url, strlen($origin));

        return $path === '' ? '/' : $path;
    }

    /**
     * Name it when checked links point at another origin: on a dev port the
     * site prints absolute URLs without the port, and "does not answer" alone
     * would read as broken pages rather than as the wrong address.
     *
     * @param list<string> $urls
     */
    private static function foreignNote(string $origin, array $urls): string
    {
        $foreign = array_filter($urls, static fn (string $url): bool => self::sameOriginPath($origin, $url) === null);
        if ($foreign === []) {
            return '';
        }
        $first = (string) reset($foreign);
        $parts = parse_url($first);
        $other = is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : $first;

        return sprintf('; %d point to %s, not %s', count($foreign), $other, $origin);
    }

    private static function answered(ProbeResponse $response): string
    {
        if ($response->status === 0) {
            return 'no answer';
        }
        $detail = 'answered ' . $response->status;
        if ($response->status >= 300 && $response->status < 400 && $response->location !== '') {
            $detail .= ' → ' . $response->location;
        }

        return $detail;
    }

    private function get(string $origin, string $path): ProbeResponse
    {
        return $this->responses[$path] ??= ($this->fetch)($origin . $path);
    }
}
