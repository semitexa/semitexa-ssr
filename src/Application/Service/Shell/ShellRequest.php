<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Shell;

use Semitexa\Core\Lifecycle\CurrentRequestStore;

/**
 * Is the client asking for this page without its chrome?
 *
 * ONE HEADER, and it is the whole protocol. A request that carries it gets the
 * page's per-page regions and nothing else; a request that does not gets
 * exactly the document it always got — same route, same handler, same
 * template. That is not a nicety: a direct hit, a bookmark, a crawler and a
 * visitor with no JavaScript must all still work, and the only way to
 * guarantee that is for the document to stay the whole truth and the fragment
 * to be derived from it.
 *
 * WHY Vary MATTERS ENOUGH TO LIVE IN THIS CLASS'S DOCBLOCK: one URL now
 * answers two bodies depending on a header. A cache that does not know the
 * header is part of the key will hand the chrome-less JSON to a real
 * navigation, and the visitor gets a page of braces. {@see VARY_HEADER} is
 * emitted on BOTH shapes for that reason — the document response has to
 * declare it too, because it is the one that gets cached.
 */
final class ShellRequest
{
    /*
     * ONE MORE THING THE HEADER DOES NOT DO: it does not override content
     * negotiation. `Accept: application/json` on a page route already means
     * "give me that page's JSON representation" — iri, meta, alternates — and
     * that is a different resource, resolved before this class is ever
     * consulted. A client asking for both gets the representation, not the
     * shell envelope. The shipped client therefore sends no Accept at all;
     * anything else hand-rolling this protocol must do the same.
     */

    public const HEADER = 'X-Semitexa-Shell';

    public const VARY_HEADER = 'Vary';

    /** Overrides the header lookup. Null means "ask the request". */
    private static ?bool $forced = null;

    /** True when the current request asked for the chrome-less shape. */
    public static function isShellRequest(): bool
    {
        if (self::$forced !== null) {
            return self::$forced;
        }

        $request = CurrentRequestStore::get();
        if ($request === null) {
            return false;
        }

        $value = $request->getHeader(self::HEADER);

        return $value !== null && trim($value) !== '' && trim($value) !== '0';
    }

    /**
     * Test seam. There is no HTTP request in a unit test, and rendering a page
     * through the real stack to assert a header is a slower, less specific
     * test than this makes possible.
     */
    public static function forceForTesting(?bool $isShell): void
    {
        self::$forced = $isShell;
    }
}
