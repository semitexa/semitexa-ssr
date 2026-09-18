<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Shell;

use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;

/**
 * Turns a finished document into the chrome-less shape, when the client asked.
 *
 * Sits at the one seam every SSR page passes through, so a project gets this
 * by MARKING ITS LAYOUT and nothing else — no branch in a template, no second
 * route, no per-page opt-in. The branch in the layout is what this exists to
 * prevent: it is a second copy of the page's structure, and a second copy
 * drifts.
 *
 * REFUSES RATHER THAN GUESSES. A page that marks no region gets its document
 * back even on a shell request. There is nothing to swap, and answering with
 * an empty envelope would leave the client replacing content with nothing at
 * all — a blank screen where a working page used to be.
 */
final class ShellResponder
{
    public function __construct(
        private readonly ShellRegionExtractor $extractor = new ShellRegionExtractor(),
    ) {}

    /**
     * Give this response its shell shape, if the client asked for one.
     *
     * Vary is set either way, and that is not symmetry for its own sake: one
     * URL now answers two bodies on a header, so the response that gets CACHED
     * — the document — is the one that has to declare it. Without that, a
     * cache hands the chrome-less JSON to a real navigation and the visitor
     * reads braces.
     */
    public function apply(ResourceResponse $response): void
    {
        // Found case-insensitively and rewritten UNDER ITS OWN KEY. Header
        // names do not care about case and ResourceResponse's array does, so
        // a response that already said `vary: Cookie` was missed here and got
        // a second `Vary: …` entry beside it. Swoole's header() is
        // case-insensitive in turn, so the later one wins and the response
        // ships without `Cookie` — a shared cache then serves one visitor's
        // page to another. One key, merged in place.
        $key = $this->varyHeaderKey($response->getHeaders());

        $response->setHeader($key, $this->mergedVary($response->getHeaders()[$key] ?? null));

        $html = $response->getContent();
        if ($html === '') {
            return;
        }

        $envelope = $this->envelopeFor($html);
        if ($envelope === null) {
            return;
        }

        $response->setContent($envelope->toJson());
        $response->setHeader('Content-Type', ShellEnvelope::CONTENT_TYPE);
    }

    /**
     * The key this response already spells `Vary` with, or ours if it has none.
     *
     * @param array<string, string> $headers
     */
    private function varyHeaderKey(array $headers): string
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, ShellRequest::VARY_HEADER) === 0) {
                return $name;
            }
        }

        return ShellRequest::VARY_HEADER;
    }

    /**
     * Adds what this URL actually varies on to whatever it varied on already.
     *
     * `Accept` is in here with the shell header, and leaving it out was a hole
     * a shared cache falls into. This URL answers THREE bodies, not two: the
     * document, the shell envelope on `X-Semitexa-Shell`, and the framework's
     * page JSON on `Accept: application/json`. Declaring only the shell header
     * tells a cache that the Accept variants are interchangeable, so it can
     * hand a browser asking for a page the JSON representation of it.
     *
     * The same negotiation from the other side is what made the client send no
     * Accept at all — see {@see ShellRequest}.
     */
    private function mergedVary(?string $existing): string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $existing)));

        foreach ([ShellRequest::HEADER, 'Accept'] as $header) {
            $already = false;
            foreach ($parts as $part) {
                if (strcasecmp($part, $header) === 0) {
                    $already = true;
                    break;
                }
            }

            if (!$already) {
                $parts[] = $header;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * The envelope for this document, or null when the request did not ask for
     * one or the page has no regions to give.
     */
    public function envelopeFor(string $html): ?ShellEnvelope
    {
        if (!ShellRequest::isShellRequest()) {
            return null;
        }

        $regions = $this->extractor->extract($html);
        if ($regions === []) {
            return null;
        }

        return new ShellEnvelope(
            url: $this->currentUrl(),
            title: $this->extractor->title($html),
            regions: $regions,
            assets: $this->extractor->assets($html),
            deferredManifest: $this->extractor->deferredManifest($html),
        );
    }

    /**
     * The address the server actually served.
     *
     * Read from the request rather than echoed from the client: the two differ
     * whenever anything canonicalises a path, and the address bar has to show
     * the second one.
     *
     * getServedPath(), not getPath(): the request reaching a handler has been
     * rebased onto the path the ROUTER matched, and the locale layer strips a
     * URL prefix to produce it. Reporting that one made `/ka/gallery` answer
     * `url: /gallery`, the client pushState'd it, and under
     * `LOCALE_URL_PREFIX=true` an unprefixed path means the default language —
     * so the page stayed Georgian and the next reload, or the link the visitor
     * shared, came back in another language. Silent until someone reloads.
     */
    private function currentUrl(): string
    {
        $request = CurrentRequestStore::get();
        if ($request === null) {
            return '';
        }

        $path = $request->getServedPath();
        $query = $request->getQueryString();

        return $query === '' ? $path : $path . '?' . $query;
    }
}
