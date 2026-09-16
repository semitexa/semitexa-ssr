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

        while (true) {
            $start = $this->findRegionStart($html, $offset, $name, $tag);
            if ($start === null) {
                break;
            }

            $end = $this->findRegionEnd($html, $start, $tag);
            if ($end === null) {
                // An unbalanced region is a broken document, not a fragment
                // worth shipping: skip it and keep the rest rather than
                // sending the client a half-open element to insert.
                $offset = $start + 1;
                continue;
            }

            $regions[$name] ??= substr($html, $start, $end - $start);
            $offset = $end;
        }

        return $regions;
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
                    $css[] = $href[1];
                }
            }
        }

        $js = [];
        $seen = [];
        if (preg_match_all('#<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>#i', $html, $scripts, PREG_SET_ORDER)) {
            foreach ($scripts as $script) {
                $src = $script[1];
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
     * it is — and that no same-named tag appears inside a comment or a script
     * within it. Both hold for markup a Twig layout produced; neither holds
     * for arbitrary HTML, and this is not a parser.
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
