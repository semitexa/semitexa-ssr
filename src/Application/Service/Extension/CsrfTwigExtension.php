<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * CSRF for plain HTML forms. A POST from a signed-in browser must carry the
 * session's token (CsrfListener); a template now writes
 *
 *     <form method="post" action="/logout">{{ csrf_field() }} …</form>
 *
 * instead of every handler reading CsrfToken and passing it down. Platform UI
 * forms (platform.form) carry their own per-submit token and need neither.
 */
#[AsTwigExtension]
final class CsrfTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('csrf_field', [$this, 'field'], ['is_safe' => ['html']]);
        TwigExtensionRegistry::registerFunction('csrf_token', [$this, 'token']);
    }

    /** `<input type="hidden" name="_csrf" value="…">`, escaped; '' outside a request. */
    public function field(): string
    {
        return CsrfField::hiddenInput();
    }

    /** The raw token (for a header or a data attribute); '' outside a request. */
    public function token(): string
    {
        return CsrfField::token();
    }
}
