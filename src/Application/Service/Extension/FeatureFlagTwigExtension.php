<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Feature\CurrentFeatureFlags;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/** `{% if feature('checkout.new-flow') %}` — the flag for this request's site and user. */
#[AsTwigExtension]
final class FeatureFlagTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('feature', [$this, 'isEnabled']);
    }

    public function isEnabled(string $flag): bool
    {
        return CurrentFeatureFlags::isEnabled($flag);
    }
}
