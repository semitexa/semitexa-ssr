<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Domain\Contract\DataProviderInterface;

#[AsService]
final class DataProviderRegistry
{
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /**
     * @var array<string, array{class: string, handles: string[]}> slot_id => provider info
     */
    private static array $providerMap = [];

    public static function register(string $slotId, string $providerClass, array $handles = []): void
    {
        $slotKey = self::normalizeSlotId($slotId);
        $handleKeys = self::normalizeHandles($handles);
        self::$providerMap[$slotKey] = [
            'class' => $providerClass,
            'handles' => $handleKeys,
        ];
    }

    /**
     * Resolve a fresh DataProvider instance for the given slot.
     * Returns null if no provider is registered.
     */
    public function resolve(string $slotId, ?string $pageHandle = null): ?DataProviderInterface
    {
        $slotKey = self::normalizeSlotId($slotId);
        $entry = self::$providerMap[$slotKey] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry['handles'] !== []) {
            if ($pageHandle === null || $pageHandle === '') {
                return null;
            }
            $handleKey = strtolower($pageHandle);
            if (!in_array($handleKey, $entry['handles'], true)) {
                return null;
            }
        }

        try {
            if (isset($this->container) && $this->container->has($entry['class'])) {
                $instance = $this->container->get($entry['class']);
            } else {
                $instance = new $entry['class']();
            }
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'DataProviderRegistry: Failed to instantiate provider', [
                'class' => $entry['class'],
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$instance instanceof DataProviderInterface) {
            return null;
        }

        return $instance;
    }

    /**
     * Check if a provider is registered and active for a given handle.
     */
    public static function hasProvider(string $slotId, ?string $handle = null): bool
    {
        $slotKey = self::normalizeSlotId($slotId);
        $entry = self::$providerMap[$slotKey] ?? null;
        if ($entry === null) {
            return false;
        }

        if ($entry['handles'] !== []) {
            if ($handle === null || $handle === '') {
                return false;
            }
            $handleKey = strtolower($handle);
            return in_array($handleKey, $entry['handles'], true);
        }

        return true;
    }

    /**
     * @return array<string, array{class: string, handles: string[]}>
     */
    public static function getAll(): array
    {
        return self::$providerMap;
    }

    public static function reset(): void
    {
        self::$providerMap = [];
    }

    private static function normalizeSlotId(string $slotId): string
    {
        return strtolower($slotId);
    }

    /**
     * @param list<string> $handles
     * @return list<string>
     */
    private static function normalizeHandles(array $handles): array
    {
        $normalized = [];
        foreach ($handles as $handle) {
            $handle = strtolower($handle);
            if ($handle !== '') {
                $normalized[] = $handle;
            }
        }
        return $normalized;
    }
}
