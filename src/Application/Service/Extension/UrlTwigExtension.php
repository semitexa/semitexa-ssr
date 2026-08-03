<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Environment;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Application\Service\Routing\UrlGenerator;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * URL functions: named-route generation, and the current request's URL in
 * relative or absolute form.
 *
 * Moved out of ModuleTemplateCatalog::registerFunctions() by
 * ep-slay-template-catalog. `current_url` and `current_absolute_url` carried a
 * byte-identical copy of the path-and-overrides logic; here the absolute form is
 * simply the relative one with an origin in front, which is what it always
 * meant.
 *
 * Origin resolution is proxy-aware on purpose. Behind a reverse proxy the
 * request's own host and scheme describe the hop, not the site — so
 * `x-forwarded-host` / `x-forwarded-proto` win, each taken as its FIRST
 * comma-separated entry (a chained proxy appends, so the client-facing value is
 * leftmost). Only when no request context offers an origin do the `APP_URL` /
 * `APP_HOST` settings answer, which is what makes these usable from CLI renders.
 */
#[AsTwigExtension]
final class UrlTwigExtension
{
    public function registerFunctions(): void
    {
        if (!class_exists(UrlGenerator::class)) {
            return;
        }

        TwigExtensionRegistry::registerFunction('url', [$this, 'route']);
        TwigExtensionRegistry::registerFunction('current_url', [$this, 'currentUrl']);
        TwigExtensionRegistry::registerFunction('current_absolute_url', [$this, 'currentAbsoluteUrl']);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function route(string $route, array $params = []): string
    {
        return UrlGenerator::to($route, $params);
    }

    /**
     * The current path, optionally with its query string replaced.
     *
     * `$overrides` REPLACES the query rather than merging into it — that is what
     * makes `current_url({page: 2})` produce a clean pagination link instead of
     * accumulating every parameter the visitor arrived with.
     *
     * @param array<string, mixed> $overrides
     */
    public function currentUrl(array $overrides = []): string
    {
        $path = self::requestPath();

        if ($overrides === []) {
            return $path;
        }

        $basePath = parse_url($path, PHP_URL_PATH);

        return (is_string($basePath) && $basePath !== '' ? $basePath : '/')
            . '?' . http_build_query($overrides);
    }

    /**
     * The current URL with its origin — for canonical tags, share links and
     * anything that leaves the page.
     *
     * @param array<string, mixed> $overrides
     */
    public function currentAbsoluteUrl(array $overrides = []): string
    {
        $path = $this->currentUrl($overrides);
        $origin = self::origin();

        return $origin !== '' ? rtrim($origin, '/') . $path : $path;
    }

    private static function requestPath(): string
    {
        $request = self::currentRequest();
        if ($request === null) {
            return '/';
        }

        $requestUri = self::stringMap($request->server ?? null)['request_uri'] ?? '';

        return $requestUri !== '' ? $requestUri : '/';
    }

    private static function origin(): string
    {
        $fromRequest = self::originFromRequest();
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        // No usable request context — a CLI render, a queue worker, a warmup.
        $appUrl = trim((string) (Environment::getEnvValue('APP_URL') ?? ''));
        if ($appUrl !== '') {
            return $appUrl;
        }

        $appHost = trim((string) (Environment::getEnvValue('APP_HOST') ?? ''));
        if ($appHost === '') {
            return '';
        }

        return sprintf('%s://%s', self::configuredScheme(), $appHost);
    }

    private static function originFromRequest(): string
    {
        $request = self::currentRequest();
        if ($request === null) {
            return '';
        }

        $headers = self::stringMap($request->header ?? null);
        $server = self::stringMap($request->server ?? null);

        $host = self::firstCsvValue($headers['x-forwarded-host'] ?? $headers['host'] ?? '');
        if ($host === '') {
            return '';
        }

        // `x-forwarded-host` is client-supplied unless a proxy overwrites it, and
        // this origin ends up in canonical and share links. When the deployment
        // says what it is — APP_URL or APP_HOST — that is the allowlist: a host
        // that does not match is discarded here, and origin() falls through to the
        // configured value. With neither configured (local dev) there is nothing
        // to check against, so the request is trusted as before.
        if (!self::isAllowedHost($host)) {
            return '';
        }

        $scheme = self::firstCsvValue($headers['x-forwarded-proto'] ?? '');
        if ($scheme === '') {
            $https = strtolower($server['https'] ?? '');
            $scheme = ($https === 'on' || $https === '1') ? 'https' : '';
        }
        if ($scheme === '') {
            $scheme = self::configuredScheme();
        }

        return sprintf('%s://%s', $scheme, $host);
    }

    /**
     * Does a request-derived host match what this deployment declares itself to be?
     *
     * Compared without port, case-insensitively: a proxy may forward
     * `example.com:443` for an origin configured as `https://example.com`, and that
     * is the same host.
     */
    private static function isAllowedHost(string $host): bool
    {
        $configured = trim((string) (Environment::getEnvValue('APP_URL') ?? ''));
        if ($configured !== '') {
            $parsed = parse_url($configured, PHP_URL_HOST);
            $configured = is_string($parsed) ? $parsed : '';
        }

        if ($configured === '') {
            $configured = trim((string) (Environment::getEnvValue('APP_HOST') ?? ''));
        }

        if ($configured === '') {
            return true;
        }

        return self::hostWithoutPort($host) === self::hostWithoutPort($configured);
    }

    private static function hostWithoutPort(string $host): string
    {
        $host = strtolower(trim($host));
        $colon = strrpos($host, ':');

        // Only strip a trailing `:port`, never a colon inside a bare IPv6 literal.
        if ($colon !== false && !str_contains(substr($host, $colon + 1), ':') && ctype_digit(substr($host, $colon + 1))) {
            $host = substr($host, 0, $colon);
        }

        return trim($host, '[]');
    }

    private static function configuredScheme(): string
    {
        // `?? 'http'` alone only defaults a *missing* entry. An APP_SCHEME that is
        // present but empty trims to '' and would build `://example.com`.
        $scheme = trim((string) (Environment::getEnvValue('APP_SCHEME') ?? ''));

        return $scheme !== '' ? $scheme : 'http';
    }

    /**
     * First entry of a comma-separated proxy header.
     *
     * Chained proxies append, so the leftmost value is the one the client
     * actually addressed.
     */
    private static function firstCsvValue(string $value): string
    {
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                return $part;
            }
        }

        return '';
    }

    private static function currentRequest(): ?object
    {
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();

        return $context === null ? null : $context[0];
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
