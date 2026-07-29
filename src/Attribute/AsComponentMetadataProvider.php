<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.component-metadata',
    summary: 'Contributes metadata to components, in priority order.',
    useWhen: 'Components need attributes decided centrally rather than per component.',
    avoidWhen: 'The metadata concerns one component only - declare it on that component.',
    replaces: [
        'repeating the same metadata in every component declaration',
    ],
)]
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsComponentMetadataProvider
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
