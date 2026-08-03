<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Locale\Configuration\LocaleConfig;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Ssr\Application\Service\I18n\Translator;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * Locale functions: prefixing a path for a locale, building the language-switch
 * link for the current page, and translation.
 *
 * Moved out of ModuleTemplateCatalog::registerFunctions() by
 * ep-slay-template-catalog.
 *
 * Two rules run through all of this. The default locale is never prefixed — the
 * canonical URL of a page in the site's own language is the bare path, and
 * emitting `/en/about` alongside `/about` would split every page in two for
 * search engines. And URL prefixing can be off entirely, in which case these
 * functions must return the path untouched rather than invent a scheme the
 * router does not serve.
 *
 * Supported locales are read from {@see LocaleContextStore} first because they
 * are per-tenant and resolved for THIS request; the boot-frozen
 * {@see LocaleConfig} set is only the fallback for before that resolution
 * happened. Reading the frozen set first would strip the wrong prefix on any
 * tenant with its own locale list.
 */
#[AsTwigExtension]
final class LocaleTwigExtension
{
    public function registerFunctions(): void
    {
        if (class_exists(LocaleContextStore::class)) {
            TwigExtensionRegistry::registerFunction('locale_url', [$this, 'localeUrl']);
            TwigExtensionRegistry::registerFunction('locale_switch_url', [$this, 'localeSwitchUrl']);
        }

        if (class_exists(Translator::class)) {
            TwigExtensionRegistry::registerFunction('trans', [$this, 'translate']);
            TwigExtensionRegistry::registerFunction('trans_choice', [$this, 'translateChoice']);
            TwigExtensionRegistry::registerFunction('locale', [$this, 'currentLocale']);
        }
    }

    /**
     * Prefix a path for a locale — or leave it alone when prefixing is off or the
     * locale is the default.
     */
    public function localeUrl(string $path, ?string $locale = null): string
    {
        return self::withLocalePrefix($path, $locale ?? LocaleContextStore::getLocale());
    }

    /**
     * The current page, addressed in another locale.
     *
     * The query string is dropped: a language switch is a navigation to the same
     * page, and carrying over filters or pagination from the previous locale is
     * more often wrong than right.
     */
    public function localeSwitchUrl(string $targetLocale): string
    {
        return self::withLocalePrefix(self::currentPathWithoutLocale(), $targetLocale);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function translate(string $key, array $params = []): string
    {
        return Translator::trans($key, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function translateChoice(string $key, int $count, array $params = []): string
    {
        return Translator::transChoice($key, $count, $params);
    }

    public function currentLocale(): string
    {
        return Translator::getLocale();
    }

    private static function withLocalePrefix(string $path, string $locale): string
    {
        if (!LocaleContextStore::isUrlPrefixEnabled()) {
            return $path;
        }

        if ($locale === LocaleContextStore::getDefaultLocale()) {
            return $path;
        }

        return '/' . $locale . '/' . ltrim($path, '/');
    }

    /**
     * The current request's path, query string and any locale prefix removed.
     */
    private static function currentPathWithoutLocale(): string
    {
        $path = self::currentPath();

        $segments = explode('/', ltrim($path, '/'), 2);
        if (in_array($segments[0], self::supportedLocales(), true)) {
            return '/' . ($segments[1] ?? '');
        }

        return $path;
    }

    private static function currentPath(): string
    {
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();
        $requestUri = '/';

        if ($context !== null) {
            $server = $context[0]->server ?? null;
            if (is_array($server) && is_scalar($server['request_uri'] ?? null)) {
                $requestUri = (string) $server['request_uri'];
            }
        }

        $basePath = parse_url($requestUri, PHP_URL_PATH);

        return is_string($basePath) && $basePath !== '' ? $basePath : '/';
    }

    /**
     * @return list<string>
     */
    private static function supportedLocales(): array
    {
        $supported = LocaleContextStore::getSupportedLocales();

        return $supported !== [] ? $supported : LocaleConfig::fromEnvironment()->supportedLocales;
    }
}
