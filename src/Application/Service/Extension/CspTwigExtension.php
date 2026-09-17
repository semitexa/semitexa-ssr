<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Http\CspNonce;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * The response's CSP nonce, for the templates that write their own script tag.
 *
 * A template is where an inline script is most natural to write and hardest to
 * fix afterwards: by the time a project turns on a nonce policy, the tags are
 * spread across partials nobody remembers. `csp_nonce_attr()` renders the
 * whole ` nonce="…"` attribute, or nothing at all when the application has no
 * nonce — so a template written this way is correct under both, and stays
 * byte-identical for consumers with no policy.
 *
 *     <script{{ csp_nonce_attr() }}>…</script>
 *
 * `csp_nonce()` is the raw value, for the cases that need it somewhere other
 * than a script tag — a `<meta name="csp-nonce">` a third-party bundle reads,
 * for one. Slicing the attribute to get the value back is how escaping gets
 * done twice, differently.
 */
#[AsTwigExtension]
final class CspTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('csp_nonce_attr', [$this, 'nonceAttribute'], ['is_safe' => ['html']]);
        TwigExtensionRegistry::registerFunction('csp_nonce', [$this, 'nonce']);
    }

    /** ` nonce="…"`, already escaped, or '' when there is no nonce. */
    public function nonceAttribute(): string
    {
        return CspNonce::attribute();
    }

    /** The raw nonce, or '' when there is no nonce. */
    public function nonce(): string
    {
        return CspNonce::value();
    }
}
