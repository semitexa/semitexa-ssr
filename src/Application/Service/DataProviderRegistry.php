<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Ssr\Domain\Contract\DataProviderInterface;

final class DataProviderRegistry
{
    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

    /**
     * @var array<string, array{class: string, handles: string[]}> slot_id => provider info
     */
    private static array $providerMap = [];

    public static function register(string $slotId, string $providerClass, array $handles = []): void
    {
        self::$providerMap[$slotId] = [
            'class' => $providerClass,
            'handles' => $handles,
        ];
    }

    /**
     * Resolve a fresh DataProvider instance for the given slot.
     * Returns null if no provider is registered.
     */
    public function resolve(string $slotId): ?DataProviderInterface
    {
        $entry = self::$providerMap[$slotId] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($this->container === null) {
            return null;
        }

        $instance = $this->container->get($entry['class']);

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
        $entry = self::$providerMap[$slotId] ?? null;
        if ($entry === null) {
            return false;
        }

        if ($handle !== null && $entry['handles'] !== []) {
            return in_array($handle, $entry['handles'], true);
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
}
