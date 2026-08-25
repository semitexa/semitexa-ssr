<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * Track R · Gap C-3 — the severity a single subscribe-loop drop deserves.
 *
 * The distinction this enum exists to make: a DROP is not an OUTAGE. The Gap C
 * reconnect loop is designed to survive dropped connections (app restart, Redis
 * restart, network blip) and it does — so a drop that self-heals on the next turn
 * is a diagnostic fact, not an operator-facing event. What actually deserves an
 * alert is the state "this loop cannot get back on", which is only observable
 * AFTER several consecutive failed reconnects.
 *
 * {@see ReconnectAlarmPolicy} maps a failure streak onto these levels; the
 * subscriber maps the level onto a {@see \Semitexa\Core\Log\StaticLoggerBridge}
 * call. Nothing here logs — the policy stays pure so it is unit-testable outside
 * a Swoole coroutine (the loop itself is not).
 */
enum ReconnectAlarmLevel
{
    /** Emit nothing at all (the drop was an interrupt or a graceful stop). */
    case Silent;

    /** Routine self-heal — recorded for forensics, never alert-worthy. */
    case Debug;

    /** The loop has missed several reconnects in a row: operator-visible. */
    case Warning;

    /** Sustained failure — the receiver is deaf and someone must look. */
    case Error;
}
