<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Core\Support\CoroutineLocal;

/**
 * Per-request store of deferred component placeholders rendered during Twig execution.
 *
 * ComponentRenderer::render() records each #[WithTransport(Sse, deferred:true)] short-circuit
 * here so LayoutRenderer / HtmlResponse can drain the list after Twig finishes, write it into
 * DeferredRequestRegistry, and include it in the manifest emitted to the client.
 *
 * Per-request isolation rides on Semitexa\Core\Support\CoroutineLocal — same mechanism the
 * ComponentRenderer already uses for slots and the current request snapshot.
 */
final class ComponentInstanceStore
{
    private const CTX_KEY = '__ssr_deferred_component_instances';

    /**
     * @param array<array-key, mixed> $props
     */
    public static function record(string $instanceId, string $componentName, array $props): void
    {
        if ($instanceId === '' || $componentName === '') {
            return;
        }

        $current = self::all();
        $current[$instanceId] = [
            'instance_id' => $instanceId,
            'name' => $componentName,
            'props' => self::sanitizeProps($props),
        ];
        CoroutineLocal::set(self::CTX_KEY, $current);
    }

    /**
     * @return array<string, array{instance_id: string, name: string, props: array<array-key, mixed>}>
     */
    public static function all(): array
    {
        $value = CoroutineLocal::get(self::CTX_KEY, []);
        return is_array($value) ? $value : [];
    }

    public static function reset(): void
    {
        CoroutineLocal::set(self::CTX_KEY, []);
    }

    /**
     * Drop any non-scalar/non-array values so the captured props survive a JSON round-trip
     * through the Swoole Table column. Mirrors DeferredRequestRegistry::sanitizeContext().
     *
     * @param array<array-key, mixed> $props
     * @return array<array-key, mixed>
     */
    private static function sanitizeProps(array $props): array
    {
        $sanitized = [];
        foreach ($props as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $normalized = self::sanitizeValue($value);
            if ($normalized === self::unsupportedMarker()) {
                continue;
            }
            $sanitized[$key] = $normalized;
        }
        return $sanitized;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return self::unsupportedMarker();
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $normalized = self::sanitizeValue($item);
            if ($normalized === self::unsupportedMarker()) {
                continue;
            }
            $out[$key] = $normalized;
        }
        return $out;
    }

    private static function unsupportedMarker(): object
    {
        static $marker;
        return $marker ??= new \stdClass();
    }
}
