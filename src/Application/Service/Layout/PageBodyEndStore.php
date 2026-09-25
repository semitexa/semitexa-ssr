<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Semitexa\Ssr\Domain\Contract\PageDocumentContributorInterface;

/**
 * Carries this request's page contributors from the pipeline, where the
 * container is, to the finished document, where `</body>` is — the same hand
 * over {@see \Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadStore}
 * makes for `</head>`.
 *
 * A document that already carries {@see self::MARKER} is left alone, so
 * nested finalization of one page adds nothing twice; a fragment (no
 * `</body>`) is never touched.
 */
final class PageBodyEndStore
{
    private const KEY = '__ssr_page_body_end_contributors';

    /** Marks a document the fragments were placed in. */
    public const MARKER = '<!--semitexa:body-end-->';

    /** @param list<PageDocumentContributorInterface> $contributors */
    public static function bind(array $contributors): void
    {
        CoroutineLocal::set(self::KEY, $contributors);
    }

    public static function reset(): void
    {
        CoroutineLocal::remove(self::KEY);
    }

    public static function inject(string $html): string
    {
        $contributors = CoroutineLocal::get(self::KEY);
        if (!is_array($contributors) || $contributors === [] || str_contains($html, self::MARKER)) {
            return $html;
        }
        // The LAST </body>: an inline <template> or an escaped sample in the
        // page may carry an earlier one.
        $bodyClose = strripos($html, '</body>');
        if ($bodyClose === false) {
            return $html;
        }

        $fragments = '';
        foreach ($contributors as $contributor) {
            if (!$contributor instanceof PageDocumentContributorInterface) {
                continue;
            }
            try {
                $fragments .= $contributor->bodyEnd();
            } catch (\Throwable $e) {
                SseSessionCoroutines::rethrowIfCancellation($e);
                StaticLoggerBridge::error('ssr', 'A page contributor failed; the page was served without its fragment', [
                    'contributor' => $contributor::class,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $fragments === '' ? $html : substr_replace($html, self::MARKER . "\n" . $fragments, $bodyClose, 0);
    }
}
