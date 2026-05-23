<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

interface ComponentMetadataProviderInterface
{
    /**
     * True if this provider contributes metadata for the given component class.
     * Implementations typically scan for the presence of specific attributes on
     * properties or the class itself.
     */
    public function supports(\ReflectionClass $componentClass): bool;

    /**
     * Returns props to merge underneath the component render context. Called
     * once per component class at boot — must be stateless and side-effect-free.
     *
     * @return array<string, mixed>
     */
    public function getProps(\ReflectionClass $componentClass): array;
}
