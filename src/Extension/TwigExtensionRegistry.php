<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Extension;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Ssr\Attributes\AsTwigExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

final class TwigExtensionRegistry
{
    /** @var array<string, array{callback: callable, options: array}> */
    private static array $functions = [];
    
    /** @var array<string, callable> */
    private static array $filters = [];
    
    private static bool $initialized = false;

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        $extensionClasses = ClassDiscovery::findClassesWithAttribute(AsTwigExtension::class);

        foreach ($extensionClasses as $class) {
            $reflection = new \ReflectionClass($class);
            
            if (!$reflection->isInstantiable()) {
                continue;
            }

            try {
                $extension = $reflection->newInstance();
                
                if (method_exists($extension, 'registerFunctions')) {
                    $extension->registerFunctions();
                }
                
                if (method_exists($extension, 'registerFilters')) {
                    $extension->registerFilters();
                }
            } catch (\Throwable $e) {
                error_log("Failed to load Twig extension {$class}: " . $e->getMessage());
            }
        }

        self::$initialized = true;
    }

    public static function registerFunction(
        string $name,
        callable $callback,
        array $options = []
    ): void {
        self::$functions[$name] = [
            'callback' => $callback,
            'options' => $options,
        ];
    }

    public static function registerFilter(string $name, callable $callback): void
    {
        self::$filters[$name] = $callback;
    }

    /** @return array<string, array{callback: callable, options: array}> */
    public static function getFunctions(): array
    {
        self::initialize();
        return self::$functions;
    }

    /** @return array<string, callable> */
    public static function getFilters(): array
    {
        self::initialize();
        return self::$filters;
    }
}
