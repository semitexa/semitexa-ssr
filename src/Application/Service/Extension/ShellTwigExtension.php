<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Ssr\Application\Service\Shell\ShellRegionExtractor;
use Semitexa\Ssr\Application\Service\Shell\ShellRequest;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * How a layout says which of its regions change per page.
 *
 * An ATTRIBUTE on the element the layout already has, not a wrapper:
 *
 *     <main class="content"{{ shell_region('main') }}>…</main>
 *
 * That is the entire consumer-facing surface of the app shell. The framework
 * knows the rest — which request asked for the chrome-less shape, how to read
 * the regions back out of the rendered document, what the client needs in
 * order to put them in place. A project declares; it does not write a router,
 * and it does not grow a second copy of its layout behind a branch.
 *
 * The name is the identity the client swaps on, so two pages sharing a layout
 * name the same region. Marking more than one is fine and is how a console
 * updates a breadcrumb and a working area in one move.
 */
#[AsTwigExtension]
final class ShellTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('shell_region', [$this, 'region'], ['is_safe' => ['html']]);
        TwigExtensionRegistry::registerFunction('is_shell_request', [$this, 'isShellRequest']);
    }

    /** ` data-shell-region="…"`, escaped, ready to sit inside an opening tag. */
    public function region(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return ' ' . ShellRegionExtractor::ATTRIBUTE . '="'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Whether THIS request asked for the chrome-less shape.
     *
     * Deliberately exposed, and deliberately not needed for the common case: a
     * layout marks its regions and never asks. It is here for the page that
     * genuinely must know — one that would otherwise do expensive work only
     * the chrome needs. Branching on it to render a different page is the
     * thing this whole mechanism exists to avoid.
     */
    public function isShellRequest(): bool
    {
        return ShellRequest::isShellRequest();
    }
}
