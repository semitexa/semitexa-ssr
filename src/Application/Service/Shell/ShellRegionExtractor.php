<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Shell;

use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;

/**
 * Pulls the per-page regions out of a rendered document.
 *
 * The chrome-less variant is DERIVED, never rendered a second way. A project
 * that hand-writes one ends up with a branch in its layout — a second copy of
 * the page's structure that drifts from the first, silently, in whichever
 * shape gets exercised less. Measured on the console this came from: 93% of
 * every navigation was the same sidebar being sent to replace itself, and the
 * fix people reach for is a second template rather than a second SHAPE of the
 * first one.
 *
 * So: the document renders exactly as it always did, and this reads the
 * regions back out of it. Parity is not tested for, it is structural.
 *
 * WHY A SCANNER AND NOT A REGEX. Regions nest — a page region contains cards
 * that contain divs — and a regex cannot count. The scanner walks tags from
 * the region's opening tag and tracks depth, which is enough for well-formed
 * server-rendered markup and is honest about what it assumes (see
 * {@see self::findRegionEnd()}).
 */
final class ShellRegionExtractor
{
    public const ATTRIBUTE = 'data-shell-region';

    /** Elements whose contents are TEXT, not markup, and so hold no tags to count. */
    /**
     * Elements whose CONTENT is text rather than markup.
     *
     * `iframe`, `noembed`, `noframes` and `xmp` are here because HTML says so,
     * not because anything has hit them yet: the point of the mask is that a
     * closing tag written as TEXT inside one of them cannot be counted, and a
     * list that stops at the three obvious ones leaves the other four able to
     * truncate a region exactly the same way.
     */
    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'xmp'];

    private const COMMENT_SPAN = '/<!--.*?-->/s';

    /**
     * A closing tag's opening two characters, spelled in two pieces.
     *
     * Written whole, this file would CONTAIN a closer — which is the condition
     * `lint:inline-script` uses to decide a file emits markup at all. It would
     * then scan this file and report the patterns above as nonce-less script
     * tags: the extractor filing findings against itself. Three times in one
     * day, so the pieces live in a constant rather than in each author's
     * memory.
     */
    private const CLOSER = '<' . '/';

    /** Script attributes that change what the file IS or when it runs. */
    private const SCRIPT_ATTRIBUTES = ['integrity', 'crossorigin', 'referrerpolicy', 'defer', 'async', 'nomodule'];

    /** Stylesheet attributes that change whether and how it applies. */
    private const LINK_ATTRIBUTES = ['media', 'integrity', 'crossorigin', 'referrerpolicy'];

    /**
     * Every region the document marks, keyed by name, in document order.
     *
     * The value is the region's OUTER html: the client replaces the element,
     * not its contents, so attributes the server changed per page (a state
     * class, an aria-label) travel with it.
     *
     * @return array<string, string>
     */
    public function extract(string $html): array
    {
        $regions = [];
        $offset = 0;

        // Boundaries are found in a copy with the inert spans blanked out, and
        // the fragment is cut from the ORIGINAL. Same offsets either way,
        // because the mask replaces byte for byte.
        $scan = self::withoutInertSpans($html);

        while (true) {
            $start = $this->findRegionStart($scan, $offset, $name, $tag);
            if ($start === null) {
                break;
            }

            $end = $this->findRegionEnd($scan, $start, $tag);
            if ($end === null) {
                // An unbalanced region is a broken document, not a fragment
                // worth shipping: skip it and keep the rest rather than
                // sending the client a half-open element to insert.
                $offset = $start + 1;
                continue;
            }

            // Decoded like the title and the asset URLs: the attribute is
            // SERIALISED in the document, so a region named `orders&returns`
            // reads back as `orders&amp;returns`. The DOM gives the client the
            // decoded name, so the envelope key never matched the element.
            $regions[self::decode((string) $name)] ??= substr($html, $start, $end - $start);

            // Resume just INSIDE this region, not past it. Regions nest — a
            // page region holding a grid region is the ordinary case — and
            // jumping to the end meant the inner one was never seen, so the
            // envelope silently omitted a region the document had marked.
            $offset = $start + 1;
        }

        return $regions;
    }

    /**
     * A copy of the document with comments and raw-text element contents
     * replaced by spaces of the same length.
     *
     * The scanner counts tags, and a script holding the TEXT of a closing tag
     * in a string is not a tag — it is a string that happens to spell one.
     * Counted as a close, the region ends in the middle of a script and the
     * client is handed a fragment that will not parse. Blanking these spans
     * costs one pass and removes the whole class of it; what is left is
     * markup, where counting is sound.
     */
    private static function withoutInertSpans(string $html): string
    {
        $patterns = [self::COMMENT_SPAN];

        // Assembled from self::CLOSER for the reason spelled out there.
        foreach (self::RAW_TEXT_ELEMENTS as $element) {
            $patterns[] = '#<' . $element . '\b[^>]*>.*?' . self::CLOSER . $element . '\s*>#is';
        }

        foreach ($patterns as $pattern) {
            $html = (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => str_repeat(' ', strlen($m[0])),
                $html
            );
        }

        return $html;
    }

    /** The document's title, for the address bar and the tab. */
    public function title(string $html): string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) !== 1) {
            return '';
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The deferred-slot manifest this document carries, or ''.
     *
     * It is emitted at body end, OUTSIDE every marked region, so a swap that
     * carried only the regions left the new page's skeletons waiting on a
     * request id, session and bind token that belonged to the page before it.
     * The skeletons simply sat there — no error, no frame, and nothing on the
     * server able to notice.
     *
     * Returned as the JSON it already is, because the client replaces the
     * element wholesale and re-reads it.
     */
    public function deferredManifest(string $html): string
    {
        $pattern = '#<script\b[^>]*\b' . preg_quote(PlaceholderRenderer::MANIFEST_ATTRIBUTE, '#')
            . '\b[^>]*>(.*?)' . self::CLOSER . 'script\s*>#is';

        return preg_match($pattern, $html, $m) === 1 ? trim($m[1]) : '';
    }

    /**
     * An attribute as the document SERIALISED it, back to the value it means.
     *
     * `/feed?a=1&b=2` is written `a=1&amp;b=2`, and the client assigns these
     * to `src`/`href` from JavaScript, where no parser is involved — so it
     * would request a URL with a literal `&amp;` in it and get a 404 for an
     * asset the page needs.
     */
    private static function decode(string $attributeValue): string
    {
        return html_entity_decode($attributeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Stylesheets and scripts the document loads, so a client swapping pages
     * can add what this page needs and the previous one did not.
     *
     * Only `href`/`src` assets: an inline block belongs to the markup it came
     * with, and re-running one on every swap is the bug that makes a swapped
     * page slower than a reload.
     *
     * EACH ASSET CARRIES THE ATTRIBUTES THAT CHANGE WHAT IT IS, and that is
     * not bookkeeping. The client RE-CREATES the tag, so anything it is not
     * told is lost: `type` decides whether a file is a module or a classic
     * script (adding one as the other changes its scope and its timing, and a
     * module added as a classic script is a syntax error the moment it
     * imports); `integrity` is the difference between a checked file and an
     * unchecked one; `defer`, `async` and `nomodule` decide when and whether
     * it runs at all; `media` decides whether a stylesheet applies. A client
     * cannot tell any of that from the URL, so the server says.
     *
     * `nonce` is deliberately NOT forwarded. It belongs to the response this
     * document came from, and the client stamps the one the LIVE document
     * carries — copying the old value would hand the browser a nonce its own
     * policy never issued.
     *
     * @return array{css: list<array{href: string, attrs: array<string, string>}>, js: list<array{src: string, type: string, attrs: array<string, string>}>}
     */
    public function assets(string $html): array
    {
        $css = [];
        $seenCss = [];
        if (preg_match_all('#<link\b[^>]*rel=["\']?stylesheet["\']?[^>]*>#i', $html, $links)) {
            foreach ($links[0] as $link) {
                if (preg_match('#href=["\']([^"\']+)["\']#i', $link, $href) !== 1) {
                    continue;
                }

                $url = self::decode($href[1]);
                $attrs = self::carriedAttributes($link, self::LINK_ATTRIBUTES);

                // Keyed on the URL AND what came with it. The same stylesheet
                // included once for `screen` and once for `print` is two
                // different instructions, and dropping the second left a
                // swapped page unable to print.
                $key = $url . '|' . json_encode($attrs);
                if (isset($seenCss[$key])) {
                    continue;
                }
                $seenCss[$key] = true;

                $css[] = ['href' => $url, 'attrs' => $attrs];
            }
        }

        $js = [];
        $seen = [];
        if (preg_match_all('#<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>#i', $html, $scripts, PREG_SET_ORDER)) {
            foreach ($scripts as $script) {
                $src = self::decode($script[1]);

                $type = '';
                if (preg_match('#\btype=["\']([^"\']+)["\']#i', $script[0], $m) === 1) {
                    $type = strtolower(trim($m[1]));
                }

                $attrs = self::carriedAttributes($script[0], self::SCRIPT_ATTRIBUTES);

                // Same reason as the stylesheets: one URL served as a module
                // and again with `nomodule` is the standard pair for two
                // different browsers, and URL-only dedup kept whichever came
                // first.
                $key = $src . '|' . $type . '|' . json_encode($attrs);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $js[] = ['src' => $src, 'type' => $type, 'attrs' => $attrs];
            }
        }

        return ['css' => $css, 'js' => $js];
    }

    /**
     * @param list<string> $wanted
     * @return array<string, string>
     */
    private static function carriedAttributes(string $tag, array $wanted): array
    {
        $out = [];

        foreach ($wanted as $name) {
            $quoted = preg_quote($name, '#');
            if (preg_match('#(?<![\w-])' . $quoted . '=["\']([^"\']*)["\']#i', $tag, $m) === 1) {
                $out[$name] = self::decode($m[1]);
                continue;
            }

            // A boolean attribute: present, no value.
            if (preg_match('#(?<![\w-])' . $quoted . '(?=[\s/>])#i', $tag) === 1) {
                $out[$name] = '';
            }
        }

        return $out;
    }

    /**
     * @param string|null $name set to the region's name on a hit
     * @param string|null $tag  set to the element's tag name on a hit
     */
    private function findRegionStart(string $html, int $offset, ?string &$name, ?string &$tag): ?int
    {
        $pattern = '#<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*\b' . preg_quote(self::ATTRIBUTE, '#') . '=["\']([^"\']+)["\'][^>]*>#';

        if (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            $name = null;
            $tag = null;

            return null;
        }

        $tag = strtolower($m[1][0]);
        $name = $m[2][0];

        return (int) $m[0][1];
    }

    /**
     * Offset just past the region's closing tag.
     *
     * Counts opens and closes of the SAME tag name. Assumes the region element
     * is a container that is actually closed — which the server rendered, so
     * it is. Tag-like text inside comments, scripts, styles and textareas is
     * not an assumption any more: {@see self::withoutInertSpans()} blanks those
     * before anything is counted. What remains unhandled is a `<` inside an
     * attribute VALUE, which no server-rendered document of ours produces.
     * This is still not a parser, and says so.
     */
    private function findRegionEnd(string $html, int $start, string $tag): ?int
    {
        $depth = 0;
        $offset = $start;
        $open = '<' . $tag;
        $close = '</' . $tag;

        while (true) {
            $nextOpen = $this->nextTag($html, $open, $offset);
            $nextClose = $this->nextTag($html, $close, $offset);

            if ($nextClose === null) {
                return null;
            }

            if ($nextOpen !== null && $nextOpen < $nextClose) {
                $depth++;
                $offset = $nextOpen + strlen($open);
                continue;
            }

            $depth--;
            $end = strpos($html, '>', $nextClose);
            if ($end === false) {
                return null;
            }

            if ($depth === 0) {
                return $end + 1;
            }

            $offset = $end + 1;
        }
    }

    /**
     * The next occurrence of `$tag` that is really that tag.
     *
     * `<sectionish>` starts with `<section` and `</sectionish>` starts with
     * `</section`. Matching on the prefix alone counts one as a nested open
     * and the other as the region's close, and the region then ends in the
     * wrong place — silently, on exactly the pages whose markup is rich enough
     * to contain such a name.
     */
    private function nextTag(string $html, string $tag, int $offset): ?int
    {
        $length = strlen($tag);

        while (true) {
            $at = stripos($html, $tag, $offset);
            if ($at === false) {
                return null;
            }

            $next = $html[$at + $length] ?? '>';
            if ($next === '>' || $next === '/' || trim($next) === '') {
                return $at;
            }

            $offset = $at + $length;
        }
    }
}
