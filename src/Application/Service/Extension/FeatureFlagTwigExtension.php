<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Feature\CurrentFeatureFlags;
use Semitexa\Core\Feature\FeatureFlagsInterface;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * `{% if feature('checkout.new-flow') %}` — the flag for this request's site
 * and user. `#[AsService]` so the catalog builds it through the container,
 * which injects $container (see ContentSeoTwigExtension).
 */
#[AsService]
#[AsTwigExtension]
final class FeatureFlagTwigExtension
{
    /**
     * For a render outside a request (a mail a worker renders, a command):
     * every flag was off there, one declared on by default too, so a mail
     * showed what the page did not. The container's flags answer instead.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('feature', [$this, 'isEnabled']);
    }

    public function isEnabled(string $flag): bool
    {
        $flags = CurrentFeatureFlags::current();
        if ($flags === null && isset($this->container)) {
            try {
                $resolved = $this->container->get(FeatureFlagsInterface::class);
                $flags = $resolved instanceof FeatureFlagsInterface ? $resolved : null;
                $subject = CurrentFeatureFlags::subject();
                if ($flags !== null && $subject !== null) {
                    $flags = $flags->forSubject($subject[0], $subject[1]);
                }
            } catch (\Throwable) {
                return false; // a check never throws: off, as before
            }
        }

        return $flags !== null && $flags->isEnabled($flag);
    }
}
