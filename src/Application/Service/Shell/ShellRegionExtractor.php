<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Shell;

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
    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea'];

    private const COMMENT_SPAN = '/<!--.*?-->/s';

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

            $regions[$name] ??= substr($html, $start, $end - $start);

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

        // Assembled rather than written out, the way InlineScriptScanner spells
        // its own closing tag in two pieces: a file containing the literal
        // closer is a file the inline-script lint scans, and the patterns just
        // above then match as nonce-less script tags — this file reporting
        // itself.
        foreach (self::RAW_TEXT_ELEMENTS as $element) {
            $patterns[] = '#<' . $element . '\b[^>]*>.*?<' . '/' . $element . '\s*>#is';
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
     * EACH SCRIPT CARRIES ITS TYPE, and that is not bookkeeping. The pipeline
     * emits `type="module"` for the ESM runtimes and a plain `defer` script
     * for a page's own file, and the two are not interchangeable: adding a
     * classic script as a module changes its scope and its timing, and adding
     * a module as a classic script is a syntax error the moment it imports
     * anything. A client cannot tell from the URL, so the server says.
     *
     * @return array{css: list<string>, js: list<array{src: string, type: string}>}
     */
    public function assets(string $html): array
    {
        $css = [];
        if (preg_match_all('#<link\b[^>]*rel=["\']?stylesheet["\']?[^>]*>#i', $html, $links)) {
            foreach ($links[0] as $link) {
                if (preg_match('#href=["\']([^"\']+)["\']#i', $link, $href) === 1) {
                    $css[] = self::decode($href[1]);
                }
            }
        }

        $js = [];
        $seen = [];
        if (preg_match_all('#<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>#i', $html, $scripts, PREG_SET_ORDER)) {
            foreach ($scripts as $script) {
                $src = self::decode($script[1]);
                if (isset($seen[$src])) {
                    continue;
                }
                $seen[$src] = true;

                $type = '';
                if (preg_match('#\btype=["\']([^"\']+)["\']#i', $script[0], $m) === 1) {
                    $type = strtolower(trim($m[1]));
                }

                $js[] = ['src' => $src, 'type' => $type];
            }
        }

        return [
            'css' => array_values(array_unique($css)),
            'js' => $js,
        ];
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
