<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/**
 * What a SITE puts in its own <head>: typed tokens, never markup.
 *
 * An analytics id or a search-console verification token belongs to neither a
 * page nor a module, so nothing owned it — after a move, analytics stopped and
 * the owner found out a week later from empty reports. It lives in
 * platform-settings under {@see self::SETTINGS_MODULE}, tenant-scoped, and the
 * framework writes the tags ({@see \Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadRenderer}).
 *
 * Tokens, not HTML, by decision: a pasted snippet is an inline script that
 * cannot carry the CSP nonce, would need a lint:inline-script exemption, and
 * turns a settings field into an XSS surface. Each value is checked against the
 * shape its vendor issues; one that does not match is not rendered and is
 * named in {@see self::$rejected}, so a typo is reported rather than shipped.
 */
final readonly class SiteHead
{
    public const SETTINGS_MODULE = 'site_head';

    public const GA4_MEASUREMENT_ID = 'ga4_measurement_id';
    public const PLAUSIBLE_DOMAIN = 'plausible_domain';
    public const GOOGLE_SITE_VERIFICATION = 'google_site_verification';
    public const BING_SITE_VERIFICATION = 'bing_site_verification';
    public const YANDEX_VERIFICATION = 'yandex_verification';

    /**
     * Every key a site may set, with the shape its value must have.
     *
     * @var array<string, non-empty-string>
     */
    public const KEYS = [
        self::GA4_MEASUREMENT_ID => '/^G-[A-Z0-9]{4,20}$/',
        self::PLAUSIBLE_DOMAIN => '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
        self::GOOGLE_SITE_VERIFICATION => '/^[A-Za-z0-9_-]{16,128}$/',
        self::BING_SITE_VERIFICATION => '/^[A-Fa-f0-9]{32}$/',
        self::YANDEX_VERIFICATION => '/^[A-Za-z0-9]{8,64}$/',
    ];

    /**
     * @param array<string, string> $values accepted values, by key
     * @param array<string, string> $rejected keys whose stored value was refused, with why
     */
    private function __construct(
        public array $values,
        public array $rejected,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Build from what platform-settings holds for {@see self::SETTINGS_MODULE}.
     *
     * @param array<array-key, mixed> $settings
     */
    public static function fromSettings(array $settings): self
    {
        $values = [];
        $rejected = [];
        foreach ($settings as $key => $raw) {
            $key = (string) $key;
            $why = self::whyRejected($key, $raw);
            if ($why !== null) {
                $rejected[$key] = $why;
                continue;
            }
            if (is_string($raw) && trim($raw) !== '') {
                $values[$key] = trim($raw);
            }
        }

        return new self($values, $rejected);
    }

    /**
     * Why this value cannot be stored under this key, or null when it can.
     * Empty means "unset" and is always acceptable.
     */
    public static function whyRejected(string $key, mixed $value): ?string
    {
        if (!array_key_exists($key, self::KEYS)) {
            return sprintf('unknown key "%s"; known: %s', $key, implode(', ', array_keys(self::KEYS)));
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            return sprintf('"%s" must be a string, got %s', $key, get_debug_type($value));
        }
        if (preg_match(self::KEYS[$key], trim($value)) !== 1) {
            return sprintf('"%s" does not look like a %s: %s', $key, str_replace('_', ' ', $key), trim($value));
        }

        return null;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
