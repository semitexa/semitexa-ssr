<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * The one place SSE tuning knobs are read out of the environment.
 *
 * Extracted during `ep-slay-sse-god-class` because more than one collaborator
 * needs the same parse with the same contract, and two private copies of it
 * would be exactly the kind of duplication that let the connection-counter leak
 * survive (see the twin cap blocks {@see SseConnectionLimiter} collapses).
 *
 * Static because it is a pure function of the environment — it holds no state,
 * so there is nothing for two coroutines to share and nothing a seam would buy.
 * That is the distinction the epic draws: stateful statics are the defect;
 * stateless parsing is not.
 */
final class SseEnv
{
    /**
     * Read a **non-negative** integer knob, falling back to `$default` on
     * anything the operator could plausibly get wrong: unset, empty, blank,
     * non-numeric, or negative.
     *
     * Silently substituting the default is the intended behaviour for tuning
     * knobs — a typo in `SSE_MAX_CONN_PER_IP` must not take the SSE endpoint
     * down. Note that `0` is a legitimate value, not a fallback trigger:
     * `SSE_MAX_CONNECTION_AGE_SECONDS=0` disables the loop's age cap.
     */
    public static function int(string $key, int $default): int
    {
        $rawValue = \getenv($key);
        $raw = trim($rawValue === false ? '' : (string) $rawValue);
        if ($raw === '') {
            return $default;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($parsed) && $parsed >= 0 ? $parsed : $default;
    }
}
