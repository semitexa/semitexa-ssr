<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\SiteHead;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Semitexa\Ssr\Domain\Model\SiteHead;

/**
 * Carries the current request's site head from the pipeline, where the
 * container is, to the finished documents, where </head> is.
 *
 * What is bound is a reader, not the values: a JSON or feed request never
 * finalizes a document, so it never pays for the settings read. The reader
 * runs at most once per request and its answer is kept, so every full
 * document the request renders gets the tags — a handler that renders an
 * iframe document or re-renders after an error does not leave the page
 * without them. A document that already carries {@see self::MARKER} is left
 * alone, which keeps nested finalization of one page idempotent; a fragment
 * (no </head>) is never touched.
 */
final class SiteHeadStore
{
    private const KEY = '__ssr_site_head_reader';
    private const VALUE = '__ssr_site_head_value';

    /** Marks a document the tags were placed in. */
    public const MARKER = '<!--semitexa:site-head-->';

    /**
     * Malformed-value warnings already logged by this worker, so a bad stored
     * value is reported once rather than on every page view. Worker-wide on
     * purpose, unlike the per-request state above; bounded.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    /** @param callable(): SiteHead $reader */
    public static function bind(callable $reader): void
    {
        CoroutineLocal::set(self::KEY, $reader);
        CoroutineLocal::remove(self::VALUE);
    }

    public static function reset(): void
    {
        CoroutineLocal::remove(self::KEY);
        CoroutineLocal::remove(self::VALUE);
        self::$warned = [];
    }

    /**
     * The document with the site's head tags placed just before </head>.
     */
    public static function inject(string $html): string
    {
        $headClose = stripos($html, '</head>');
        if ($headClose === false || str_contains($html, self::MARKER)) {
            return $html;
        }

        $head = self::resolve();
        if ($head === null) {
            return $html;
        }

        $tags = SiteHeadRenderer::render($head);

        return $tags === '' ? $html : substr_replace($html, self::MARKER . "\n" . $tags, $headClose, 0);
    }

    /**
     * This request's head values, read once; null when there is no reader or
     * the read failed. A failure is remembered too, so it is logged once per
     * request rather than once per document.
     */
    private static function resolve(): ?SiteHead
    {
        if (CoroutineLocal::has(self::VALUE)) {
            $cached = CoroutineLocal::get(self::VALUE);

            return $cached instanceof SiteHead ? $cached : null;
        }

        $reader = CoroutineLocal::get(self::KEY);
        if (!is_callable($reader)) {
            return null;
        }

        try {
            $head = $reader();
        } catch (\Throwable $e) {
            // A cancelled coroutine is not a failed read: let it unwind.
            SseSessionCoroutines::rethrowIfCancellation($e);
            // Loud on purpose: a head that silently loses its analytics id is
            // the incident this exists to prevent.
            StaticLoggerBridge::error('ssr', 'Site head settings could not be read; the page was served without them', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            CoroutineLocal::set(self::VALUE, false);

            return null;
        }

        if (!$head instanceof SiteHead) {
            CoroutineLocal::set(self::VALUE, false);

            return null;
        }
        CoroutineLocal::set(self::VALUE, $head);

        if ($head->rejected !== []) {
            $key = md5(serialize($head->rejected));
            if (!isset(self::$warned[$key])) {
                if (count(self::$warned) >= 100) {
                    self::$warned = [];
                }
                self::$warned[$key] = true;
                StaticLoggerBridge::warning('ssr', 'Site head values were not rendered because they are malformed', [
                    'rejected' => $head->rejected,
                ]);
            }
        }

        return $head;
    }
}
