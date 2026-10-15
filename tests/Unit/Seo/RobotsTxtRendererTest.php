<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Seo\RobotsTxtRenderer;

/**
 * The fallback robots.txt keeps crawlers off the framework's own service
 * endpoints (/_feed, /_auth, /__explorer, /__observatory, /__semitexa_kiss, ...)
 * and lets the operator add more paths through ROBOTS_DISALLOW.
 */
final class RobotsTxtRendererTest extends TestCase
{
    private const ENV_KEYS = ['ROBOTS_DISALLOW', 'ROBOTS_HOST', 'ROBOTS_SITEMAP_URL', 'AI_SITEMAP_URL', 'APP_URL'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->saved[$key] = getenv($key);
            // Declared-but-empty, so a developer's .env cannot leak into the assertions.
            putenv($key . '=');
        }
        putenv('APP_URL=https://example.test');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    #[Test]
    public function the_reserved_prefix_is_disallowed_by_default(): void
    {
        $lines = $this->render();

        self::assertSame(['User-agent: *', 'Allow: /', 'Disallow: /_', ''], array_slice($lines, 0, 4));
        self::assertSame(['Disallow: /_'], $this->disallowLines($lines));
    }

    #[Test]
    public function configured_entries_follow_the_reserved_prefix_in_order(): void
    {
        putenv('ROBOTS_DISALLOW=/admin,/cart, /checkout ');

        self::assertSame(
            ['Disallow: /_', 'Disallow: /admin', 'Disallow: /cart', 'Disallow: /checkout'],
            $this->disallowLines($this->render()),
        );
    }

    #[Test]
    public function blank_invalid_and_duplicate_entries_are_normalised_or_dropped(): void
    {
        putenv('ROBOTS_DISALLOW=, ,admin,/admin,/a b,/x#y,/_,/search/,/search/');

        self::assertSame(
            ['/_', '/admin', '/search/'],
            RobotsTxtRenderer::disallowedPaths(),
            'blanks skipped, a missing slash added, whitespace/# entries dropped, duplicates removed',
        );
    }

    #[Test]
    public function disallow_rules_stay_in_the_user_agent_group_before_sitemap_and_host(): void
    {
        putenv('ROBOTS_DISALLOW=/admin');
        putenv('ROBOTS_HOST=example.test');
        $lines = $this->render();

        $firstBlank = array_search('', $lines, true);
        $disallowIndexes = array_keys(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Disallow:')));
        $sitemap = $this->indexOfPrefix($lines, 'Sitemap: ');
        $host = $this->indexOfPrefix($lines, 'Host: ');

        self::assertSame([2, 3], $disallowIndexes);
        self::assertGreaterThan(max($disallowIndexes), $firstBlank, 'no blank line splits the User-agent group');
        self::assertSame('Sitemap: https://example.test/sitemap.xml', $lines[$sitemap]);
        self::assertSame('Host: example.test', $lines[$host]);
        self::assertGreaterThan(max($disallowIndexes), $sitemap);
        self::assertSame($sitemap + 1, $host);
    }

    /** @return list<string> */
    private function render(): array
    {
        return explode("\n", rtrim(RobotsTxtRenderer::render(), "\n"));
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function disallowLines(array $lines): array
    {
        return array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Disallow:')));
    }

    /** @param list<string> $lines */
    private function indexOfPrefix(array $lines, string $prefix): int
    {
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, $prefix)) {
                return $i;
            }
        }
        self::fail("no line starts with {$prefix}");
    }
}
