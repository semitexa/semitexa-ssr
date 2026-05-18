<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Attribute\SatisfiesServiceContract;

/**
 * Default framework binding for {@see UiResponseDispatcherInterface}.
 *
 * Returns a stable, safe envelope indicating that no concrete UI response
 * dispatcher is installed yet. This is the framework-only baseline:
 * `POST /__ui/event` accepts, validates, verifies signed-context, and
 * surfaces a deterministic `dispatcher_not_configured` reason code so
 * downstream consumers can distinguish "the framework endpoint is up"
 * from "a real dispatcher took over".
 *
 * Marked `#[SatisfiesServiceContract]` so the framework's
 * service-contract registry binds it as the default. Downstream
 * packages (e.g. `semitexa-platform-ui`) override by registering their
 * own `#[SatisfiesServiceContract(of: UiResponseDispatcherInterface::class)]`
 * implementation — the registry's module-extends ordering puts the
 * extending module ahead of the framework so the downstream wins.
 *
 * Why not just leave the field nullable on the handler:
 *
 *   - Forces dispatchers to opt in explicitly via the contract registry,
 *     which is the same mechanism every other framework extension point
 *     uses ({@see \Semitexa\Ssr\Application\Service\Async\SseAsyncResultDelivery}
 *     binds `AsyncResultDeliveryInterface` the same way).
 *   - Makes the "no dispatcher" branch easy to test deterministically.
 *   - Gives the endpoint a stable reason code (`dispatcher_not_configured`)
 *     callers can match against without sniffing the handler's source.
 */
#[SatisfiesServiceContract(of: UiResponseDispatcherInterface::class)]
final class NotConfiguredUiResponseDispatcher implements UiResponseDispatcherInterface
{
    public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult
    {
        // The envelope + claims are intentionally unused at the framework
        // baseline — they're only here so the interface signature matches
        // what a real dispatcher will need. Downstream replacement is the
        // ONLY way information from the envelope reaches a handler.
        unset($envelope, $verifiedClaims);

        return UiResponseDispatchResult::notConfigured();
    }
}
