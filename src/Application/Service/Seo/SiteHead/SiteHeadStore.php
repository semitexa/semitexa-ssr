<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\SiteHead;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Domain\Model\SiteHead;

/**
 * Carries the current request's site head from the pipeline, where the
 * container is, to the finished document, where </head> is.
 *
 * What is bound is a reader, not the values: a JSON or feed request never
 * finalizes a document, so it never pays for the settings read. The reader is
 * taken on first use, which makes {@see inject()} idempotent across the nested
 * renders one page can finalize — only a full document (one with </head>)
 * takes it, so a fragment rendered first does not use it up.
 */
final class SiteHeadStore
{
    private const KEY = '__ssr_site_head_reader';

    /** @param callable(): SiteHead $reader */
    public static function bind(callable $reader): void
    {
        CoroutineLocal::set(self::KEY, $reader);
    }

    public static function reset(): void
    {
        CoroutineLocal::remove(self::KEY);
    }

    /**
     * The document with the site's head tags placed just before </head>.
     */
    public static function inject(string $html): string
    {
        $headClose = stripos($html, '</head>');
        if ($headClose === false) {
            return $html;
        }

        $reader = CoroutineLocal::get(self::KEY);
        if (!is_callable($reader)) {
            return $html;
        }
        CoroutineLocal::remove(self::KEY);

        try {
            $head = $reader();
        } catch (\Throwable $e) {
            // Loud on purpose: a head that silently loses its analytics id is
            // the incident this exists to prevent.
            StaticLoggerBridge::error('ssr', 'Site head settings could not be read; the page was served without them', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $html;
        }

        if (!$head instanceof SiteHead) {
            return $html;
        }

        if ($head->rejected !== []) {
            StaticLoggerBridge::warning('ssr', 'Site head values were not rendered because they are malformed', [
                'rejected' => $head->rejected,
            ]);
        }

        $tags = SiteHeadRenderer::render($head);

        return $tags === '' ? $html : substr_replace($html, $tags, $headClose, 0);
    }
}
