<?php

declare(strict_types=1);

namespace Semitexa\Ssr\I18n;

use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Locale\Context\LocaleManager;
use Semitexa\Locale\I18n\Loader\JsonFileLoader;
use Semitexa\Locale\I18n\TranslationCatalog;
use Semitexa\Locale\I18n\TranslationService;

/**
 * Static facade for backward compatibility.
 *
 * Delegates to TranslationService internally. Prefer injecting
 * TranslationService via DI in new code.
 *
 * @deprecated Use Semitexa\Locale\I18n\TranslationService via DI instead.
 */
final class Translator
{
    private static ?TranslationService $service = null;
    private static ?LocaleContextInterface $localeContext = null;
    private static bool $initialized = false;

    /**
     * Set the backing TranslationService (call at worker boot).
     */
    public static function setService(TranslationService $service, ?LocaleContextInterface $localeContext = null): void
    {
        self::$service = $service;
        self::$localeContext = $localeContext ?? self::resolveLocaleContext();
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

        return self::$service->trans($key, $params);
    }

    public static function transChoice(string $key, int $count, array $params = []): string
    {
        self::initialize();

        return self::$service->transChoice($key, $count, $params);
    }

    public static function setLocale(string $locale): void
    {
        self::initialize();

        self::$localeContext->setLocale($locale);
    }

    public static function getLocale(): string
    {
        self::initialize();

        return self::$localeContext->getLocale();
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
        self::$initialized = false;
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
