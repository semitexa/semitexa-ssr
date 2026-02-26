<?php

declare(strict_types=1);

namespace Semitexa\Ssr\I18n;

use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Locale\Context\LocaleManager;

final class Translator
{
    private static array $locales = [];
    private static bool $initialized = false;

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::loadLocales();
        self::$initialized = true;
    }

    private static function loadLocales(): void
    {
        $modulesRoot = ProjectRoot::get() . '/src/modules';
        
        if (!is_dir($modulesRoot)) {
            return;
        }

        foreach (glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $localesDir = $moduleDir . '/Application/View/locales';
            
            if (!is_dir($localesDir)) {
                continue;
            }

            $module = basename($moduleDir);
            
            foreach (glob($localesDir . '/*.json') ?: [] as $file) {
                $locale = basename($file, '.json');
                $messages = json_decode(file_get_contents($file), true) ?? [];
                
                self::$locales[$locale][$module] = $messages;
            }
        }
    }

    public static function trans(string $key, array $params = []): string
    {
        self::initialize();

        $message = self::getMessage($key);
        
        foreach ($params as $k => $v) {
            $message = str_replace("{{$k}}", $v, $message);
        }

        return $message;
    }

    public static function transChoice(string $key, int $count, array $params = []): string
    {
        self::initialize();

        $messages = self::getMessageWithPlurals($key);
        
        $message = match (true) {
            $count === 1 => $messages['one'] ?? $messages['other'] ?? $key,
            default => $messages['other'] ?? $messages['one'] ?? $key,
        };

        $message = str_replace(':count', (string) $count, $message);
        $message = str_replace('{{count}}', (string) $count, $message);

        foreach ($params as $k => $v) {
            $message = str_replace("{{$k}}", $v, $message);
        }

        return $message;
    }

    private static function getMessage(string $key): string
    {
        $locale = self::getCurrentLocale();
        $fallback = self::getFallbackLocale();

        if (isset(self::$locales[$locale])) {
            foreach (self::$locales[$locale] as $module => $messages) {
                if (isset($messages[$key])) {
                    return $messages[$key];
                }
            }
        }

        if ($fallback !== $locale && isset(self::$locales[$fallback])) {
            foreach (self::$locales[$fallback] as $module => $messages) {
                if (isset($messages[$key])) {
                    return $messages[$key];
                }
            }
        }

        return $key;
    }

    private static function getMessageWithPlurals(string $key): array
    {
        $message = self::getMessage($key);
        
        if (str_contains($message, '|')) {
            $parts = array_map('trim', explode('|', $message));
            return [
                'one' => $parts[0] ?? $message,
                'other' => $parts[1] ?? $parts[0] ?? $message,
            ];
        }

        return ['other' => $message];
    }

    public static function setLocale(string $locale): void
    {
        try {
            LocaleManager::getInstance()->setLocale($locale);
        } catch (\Throwable) {
        }
    }

    public static function getLocale(): string
    {
        return self::getCurrentLocale();
    }

    private static function getCurrentLocale(): string
    {
        try {
            return LocaleManager::getInstance()->getLocale();
        } catch (\Throwable) {
            return 'en';
        }
    }

    private static function getFallbackLocale(): string
    {
        try {
            return LocaleManager::getInstance()->getFallbackLocale() ?? 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }
}
