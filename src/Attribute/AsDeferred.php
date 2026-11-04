<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\InternalAttribute;

/**
 * @api
 *
 * @deprecated 2026.11.04 Nothing reads this attribute, so a class carrying it renders
 *             as if it were not there. Use #[AsSlotResource(deferred: true)]; see
 *             `ai:ask mechanisms --id=ssr.deferred`.
 */
#[InternalAttribute(reason: 'Deprecated and read by nothing: ssr.deferred is advertised on AsSlotResource.')]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsDeferred
{
    public function __construct(
        public string $slot,
        public string $mode = 'html',
        public int $priority = 0,
        public int $cacheTtl = 0,
        public ?string $skeletonTemplate = null,
    ) {}
}
