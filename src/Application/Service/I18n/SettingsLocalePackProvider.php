<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\I18n;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Locale\Configuration\LocaleConfig;
use Semitexa\Locale\Domain\Contract\LocalePackProviderInterface;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * The current tenant's language pack, read from tenant-scoped platform_settings
 * (module `locale`): `default`, `fallback`, `supported` (string[]). Overlays the
 * global {@see LocaleConfig}; anything the tenant does not set falls through.
 *
 * SettingsStore is already #[TenantScoped], so getAll('locale') returns THIS
 * tenant's values — no explicit tenant handling here. Called once per request
 * during locale resolution (not the trans() hot path), so a single settings
 * read is fine. Any failure (no table / DB hiccup) degrades to the global pack.
 */
#[AsService]
#[SatisfiesServiceContract(of: LocalePackProviderInterface::class)]
final class SettingsLocalePackProvider implements LocalePackProviderInterface
{
    private const MODULE = 'locale';

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    public function resolvedPack(LocaleConfig $base): LocaleConfig
    {
        try {
            $pack = $this->settings->getAll(self::MODULE);
        } catch (\Throwable) {
            return $base;
        }
        if ($pack === []) {
            return $base;
        }

        $supported = $base->supportedLocales;
        if (is_array($pack['supported'] ?? null) && $pack['supported'] !== []) {
            $codes = [];
            foreach ($pack['supported'] as $code) {
                if (is_string($code) && $code !== '') {
                    $codes[] = $code;
                }
            }
            if ($codes !== []) {
                $supported = $codes;
            }
        }

        $default = is_string($pack['default'] ?? null) && $pack['default'] !== ''
            ? $pack['default']
            : $base->defaultLocale;
        $fallback = is_string($pack['fallback'] ?? null) && $pack['fallback'] !== ''
            ? $pack['fallback']
            : $base->fallbackLocale;

        // A tenant default/fallback outside its own supported set would make a
        // broken pack — fall back to the base value rather than serve a locale
        // the tenant does not offer.
        if (!in_array($default, $supported, true)) {
            $default = in_array($base->defaultLocale, $supported, true) ? $base->defaultLocale : ($supported[0] ?? $base->defaultLocale);
        }

        return new LocaleConfig(
            enabled: $base->enabled,
            defaultLocale: $default,
            fallbackLocale: $fallback,
            supportedLocales: $supported,
            resolverPriority: $base->resolverPriority,
            urlPrefixEnabled: $base->urlPrefixEnabled,
            urlRedirectDefault: $base->urlRedirectDefault,
        );
    }
}
