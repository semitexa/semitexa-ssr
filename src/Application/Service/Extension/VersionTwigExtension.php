<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Support\FrameworkVersion;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * `semitexa_version()` — the running release, for a footer to print.
 *
 * Lives here rather than in core because core has no Twig integration and
 * cannot depend on ssr; core owns the answer, this owns the exposure.
 *
 * Returns null on a working tree, where core resolves to a dev version
 * rather than a tag. Templates are expected to guard:
 *
 *     {% set v = semitexa_version() %}
 *     © {{ "now"|date("Y") }} Semitexa{% if v %} · {{ v }}{% endif %}
 *
 * Printing it unguarded would leave a dangling separator on every
 * development build.
 */
#[AsTwigExtension]
final class VersionTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('semitexa_version', [$this, 'current']);
    }

    public function current(): ?string
    {
        return FrameworkVersion::current();
    }
}
