<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo;

use Semitexa\Core\Environment;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Tenancy\Support\TenantUrlResolver;

final class AiSitemapLocator
{
    public const PATH = '/sitemap.json';

    public static function relativePath(): string
    {
        return self::PATH;
    }

    public static function absoluteUrl(?Request $request = null, ?TenantContextInterface $tenantContext = null): string
    {
        $override = trim((string) (Environment::getEnvValue('AI_SITEMAP_URL') ?? ''));
        if ($override !== '') {
            return $override;
        }

        return rtrim(self::resolveOrigin($request, $tenantContext), '/') . self::PATH;
    }

    public static function originUrl(?Request $request = null, ?TenantContextInterface $tenantContext = null): string
    {
        return rtrim(self::resolveOrigin($request, $tenantContext), '/');
    }

    private static function resolveOrigin(?Request $request = null, ?TenantContextInterface $tenantContext = null): string
    {
        $tenantOrigin = self::resolveOriginFromTenantContext($tenantContext);
        if ($tenantOrigin !== null) {
            return $tenantOrigin;
        }

        $requestOrigin = self::resolveOriginFromRequest($request);
        if ($requestOrigin !== null) {
            return $requestOrigin;
        }

        $appUrl = trim((string) (Environment::getEnvValue('APP_URL') ?? ''));
        if ($appUrl !== '') {
            return $appUrl;
        }

        $hostHint = trim((string) (Environment::getEnvValue('ROBOTS_HOST') ?? ''));
        if ($hostHint !== '') {
            if (preg_match('#^https?://#i', $hostHint) === 1) {
                return $hostHint;
            }

            return 'http://' . $hostHint;
        }

        $scheme = trim((string) (Environment::getEnvValue('APP_SCHEME') ?? 'http'));
        $host = trim((string) (Environment::getEnvValue('APP_HOST') ?? 'localhost'));
        $port = self::resolvePort();

        if ($port === null || self::isDefaultPort($scheme, $port)) {
            return sprintf('%s://%s', $scheme, $host);
        }

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    private static function resolveOriginFromTenantContext(?TenantContextInterface $tenantContext): ?string
    {
        if (!$tenantContext instanceof TenantContextInterface) {
            return null;
        }

        $organization = $tenantContext->getLayer(new OrganizationLayer());
        $tenantId = trim($organization?->rawValue() ?? '');
        if ($tenantId === '' || $tenantId === 'default') {
            return null;
        }

        return TenantUrlResolver::resolveBaseUrl($tenantId, preferPublic: null);
    }

    private static function resolveOriginFromRequest(?Request $request): ?string
    {
        if (!$request instanceof Request) {
            return null;
        }

        $host = $request->getHost();
        if ($host === '') {
            return null;
        }

        $origin = $request->getOrigin();

        return $origin !== '' ? $origin : null;
    }

    private static function resolvePort(): ?int
    {
        $appPort = trim((string) (Environment::getEnvValue('APP_PORT') ?? ''));
        if ($appPort !== '' && ctype_digit($appPort)) {
            return (int) $appPort;
        }

        $swoolePort = trim((string) (Environment::getEnvValue('SWOOLE_PORT') ?? ''));
        if ($swoolePort !== '' && ctype_digit($swoolePort)) {
            return (int) $swoolePort;
        }

        return null;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
