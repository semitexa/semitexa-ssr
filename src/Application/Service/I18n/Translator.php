<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\I18n;

use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Locale\Context\LocaleManager;
use Semitexa\Locale\Application\Service\I18n\JsonFileLoader;
use Semitexa\Locale\Application\Service\I18n\TranslationCatalog;
use Semitexa\Locale\Application\Service\I18n\TranslationService;

/**
 * Static facade for backward compatibility.
 *
 * Delegates to TranslationService internally. Prefer injecting
 * TranslationService via DI in new code.
 *
 * @deprecated Use Semitexa\Locale\Application\Service\I18n\TranslationService via DI instead.
 */
final class Translator
{
    private const CTX_LOCALE = '__ssr_translator_locale_context';

    /** @worker-scoped Set once at boot. */
    private static ?TranslationService $service = null;
    /** @worker-scoped Boot-time locale context (used as default). */
    private static ?LocaleContextInterface $localeContext = null;
    /** @worker-scoped */
    private static bool $initialized = false;

    /**
     * Set the backing TranslationService (call at worker boot).
     */
    public static function setService(TranslationService $service, ?LocaleContextInterface $localeContext = null): void
    {
        self::$service = $service;
        self::$localeContext = $localeContext ?? self::resolveLocaleContext();
        self::clearRequestLocaleContext();
        self::$initialized = true;
    }

    /**
     * Get or lazily create the TranslationService.
     */
    public static function getService(): TranslationService
    {
        self::initialize();

        return self::$service;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$localeContext = self::resolveLocaleContext();
        self::$service = self::buildService(self::$localeContext);
        self::$initialized = true;
    }

    public static function trans(string $key, array $params = []): string
    {
        self::initialize();

        return self::$service->trans($key, $params, self::getRequestLocaleContext()->getLocale());
    }

    public static function transChoice(string $key, int $count, array $params = []): string
    {
        self::initialize();

        return self::$service->transChoice($key, $count, $params, self::getRequestLocaleContext()->getLocale());
    }

    public static function setLocale(string $locale): void
    {
        self::initialize();

        $ctx = self::getRequestLocaleContext();
        $ctx->setLocale($locale);
    }

    public static function getLocale(): string
    {
        self::initialize();

        return self::getRequestLocaleContext()->getLocale();
    }

    /**
     * Get the locale context for the current request/coroutine.
     * In Swoole, each coroutine gets its own clone to prevent cross-request locale leaks.
     */
    private static function getRequestLocaleContext(): LocaleContextInterface
    {
        $ctx = CoroutineLocal::get(self::CTX_LOCALE);
        if ($ctx instanceof LocaleContextInterface) {
            return $ctx;
        }

        if (self::$localeContext === null) {
            self::$localeContext = self::resolveLocaleContext();
        }

        // Clone the boot-time context so mutations are coroutine-local.
        $cloned = clone self::$localeContext;
        CoroutineLocal::set(self::CTX_LOCALE, $cloned);

        return $cloned;
    }

    /**
     * Reset state (for tests).
     */
    public static function reset(): void
    {
        if (self::$localeContext !== null && class_exists(LocaleManager::class) && self::$localeContext instanceof LocaleManager) {
            self::$localeContext->setLocale('en');
        }

        self::$service = null;
        self::$localeContext = null;
        self::clearRequestLocaleContext();
        self::$initialized = false;
    }

    private static function clearRequestLocaleContext(): void
    {
        CoroutineLocal::set(self::CTX_LOCALE, null);
    }

    private static function resolveLocaleContext(): LocaleContextInterface
    {
        return class_exists(LocaleManager::class)
            ? LocaleManager::getInstance()
            : \Semitexa\Core\Locale\DefaultLocaleContext::getInstance();
    }

    private static function buildService(LocaleContextInterface $localeContext): TranslationService
    {
        $catalog = new TranslationCatalog();
        $modulesRoot = ProjectRoot::get() . '/src/modules';
        $loader = new JsonFileLoader($modulesRoot);
        $loader->load($catalog);

        return new TranslationService($catalog, $localeContext);
    }
}
