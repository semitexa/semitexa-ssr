<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * Canonical framework contract for dispatching a validated UI event.
 *
 * Lives in `semitexa-ssr` because the endpoint that calls it
 * ({@see \Semitexa\Ssr\Application\Handler\PayloadHandler\UiEventEndpointHandler}
 * routed at `POST /__ui/event`) is the canonical framework inbound. Per
 * `packages/semitexa-platform-ui/docs/transport-architecture.md` ADR-0001
 * §4.1, this is the contract that `/__ui/event` and (eventually) the
 * temporary `/__ui/dispatch` compatibility endpoint both call into.
 *
 * Dependency direction:
 *
 *   - `semitexa-ssr` defines the contract.
 *   - `semitexa-platform-ui` (or any future component package) provides
 *     the concrete implementation through `#[SatisfiesServiceContract]`.
 *   - The framework MUST NOT depend on platform-ui internals — the
 *     `composer.json` graph is one-way (platform-ui → ssr).
 *
 * Call-time invariants the endpoint guarantees before `dispatch()` runs:
 *
 *   - `$envelope` was built by {@see UiEventEnvelope::fromArray()}, so its
 *     shape is already validated (required fields present + correct types,
 *     handler-selection smuggling rejected, schema version supported).
 *   - `$verifiedClaims` is the result of
 *     {@see SignedContext::verify($envelope->signedContext)} — verified
 *     non-null. A `null` claims array (signature failure, expired, wrong
 *     purpose, tampered) NEVER reaches the dispatcher; the endpoint
 *     short-circuits with a `signedContext` validation error.
 *
 * What the dispatcher MUST NOT do:
 *
 *   - Re-verify the signed context (already done; double-verification
 *     would invite skew bugs).
 *   - Throw a non-throwable string / non-object error to the caller. The
 *     endpoint catches `\Throwable` and serialises it into a safe
 *     `ui_event_dispatcher_failure` envelope. Throwing is OK; leaking
 *     stack traces / FQCNs / secrets in the result body is not.
 *   - Overwrite the canonical envelope keys via `UiResponseDispatchResult::$body`
 *     (the endpoint enforces an allow-list — see the result's docblock).
 *
 * Default binding:
 *
 *   {@see NotConfiguredUiResponseDispatcher} is the framework's
 *   `#[SatisfiesServiceContract]` default. It returns a stable
 *   `accepted / foundation / dispatcher_not_configured` envelope so the
 *   endpoint never crashes obscurely when no concrete platform dispatcher
 *   is installed. Downstreams override by binding their own
 *   `#[SatisfiesServiceContract(of: UiResponseDispatcherInterface::class)]`
 *   implementation; the service-contract registry's module-extends order
 *   ensures the downstream wins.
 */
interface UiResponseDispatcherInterface
{
    /**
     * Dispatch the validated event.
     *
     * @param UiEventEnvelope      $envelope         Already-validated envelope.
     * @param array<string, mixed> $verifiedClaims   Verified signed-context
     *                                               claims (non-null at the
     *                                               call site).
     * @return UiResponseDispatchResult              Typed result the endpoint
     *                                               serialises into HTTP.
     */
    public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult;
}
