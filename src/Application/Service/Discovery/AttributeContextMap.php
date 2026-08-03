<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Discovery;

/**
 * Coerce a resolved attribute argument into a string-keyed map.
 *
 * Attribute arguments arrive as whatever the author wrote, then pass through
 * EnvValueResolver, so a `context:` can legitimately come back as a non-array or
 * as a list. The registries want a string-keyed map; anything else collapses to
 * an empty one rather than blowing up a boot over a cosmetic declaration.
 *
 * Lived in AttributeDiscovery as a private static until ep-slay-attribute-discovery
 * moved slot discovery out of core — it only ever served these contributors.
 */
trait AttributeContextMap
{
    /**
     * @return array<string, mixed>
     */
    private static function coerceStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
