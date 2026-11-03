<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Says once per page handle and worker that a page's deferred content renders
 * inline because its request cannot be stored. A page whose context is always
 * too big said it on every request: the demo home page, 172 KB against a
 * 32 KB column, wrote about 300 warnings a second under load (dev:bench,
 * 2026-11-03). Its next occurrences say nothing new.
 */
final class DeferredInlineWarning
{
    /** At most this many handles remembered per worker; past it a handle may warn again. */
    private const MAX_HANDLES = 256;

    /** @var array<string, true> */
    #[WorkerState('The page handles this worker has already warned about; holds no request data.')]
    private static array $warned = [];

    public static function once(string $handle): void
    {
        if (isset(self::$warned[$handle])) {
            return;
        }
        if (count(self::$warned) >= self::MAX_HANDLES) {
            self::$warned = [];
        }
        self::$warned[$handle] = true;
        StaticLoggerBridge::warning('ssr', 'Deferred content rendered inline: the request cannot be stored for deferred rendering (page context over SSR_DEFERRED_CONTEXT_SIZE or nested past 32 levels, request snapshot over SSR_REQUEST_SNAPSHOT_SIZE, or a value JSON cannot encode). Said once per page and worker.', ['handle' => $handle]);
    }

    /** For tests: forget what this worker has said. */
    public static function reset(): void
    {
        self::$warned = [];
    }
}
