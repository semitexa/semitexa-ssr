<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/**
 * The ONE identity of a rendered component instance.
 *
 * Minted once per render by the component renderer and handed to the template
 * as `_component_id`; everything that addresses the instance uses it — its
 * signed event contexts, the effects that target it, its client mount. A
 * re-render passes the same id back in as the `instanceId` prop, so the
 * browser morphs the instance in place instead of seeing a new one.
 */
final class ComponentInstanceId
{
    public const PREFIX = 'uci_';
    public const SAFE_PATTERN = '/\Auci_[A-Za-z0-9_-]{1,64}\z/';

    public static function mint(): string
    {
        return self::PREFIX . bin2hex(random_bytes(8));
    }

    /** @phpstan-assert-if-true string $id */
    public static function isSafe(mixed $id): bool
    {
        return is_string($id) && preg_match(self::SAFE_PATTERN, $id) === 1;
    }
}
