<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseFeedHandler;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Contract\DynamicallyScopedFeedInterface;
use Semitexa\Ssr\Domain\Contract\SseCollectionFeedPayloadInterface;
use Semitexa\Ssr\Domain\Contract\SseDocumentFeedPayloadInterface;
use Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionAttachment;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * SSE transport unification · Phase 1 — the production {@see SubscriptionFactoryInterface}.
 *
 * Builds a held-open feed subscription on the owning worker from a serialized
 * subscribe control, re-using the SAME primitives the standalone connect uses so
 * a multiplexed subscription is indistinguishable from a dedicated-stream one
 * once attached:
 *  - {@see RouteRegistry::findRouteTyped()} (with the handler registry, so the
 *    re-run re-runs the real handler) — the same call as
 *    {@see AbstractSseFeedHandler::buildReRunContext()};
 *  - {@see PayloadHydrator::hydrate()} to re-hydrate the feed DTO from the
 *    rebuilt request (the same hydrator the route pipeline ran on connect);
 *  - {@see AbstractSseFeedHandler::watchScopesOf()} UNION the DTO's dynamic
 *    scopes — the same scope resolution;
 *  - tenant id/blob taken from the tenant the KISS connection captured at connect
 *    time (passed by the control handler) so the record's scoping is independent
 *    of which coroutine drains the control; falling back to THIS coroutine's
 *    established context — mirroring the standalone helpers — when not supplied.
 *    The subject ref (re-auth anchor) is re-resolved from this coroutine, which
 *    is the connection's own coroutine while the connection is live.
 *
 * The factory never authorizes: it produces the two tiers; the control handler
 * hands the {@see ReRunContext} to the existing re-runner, whose auth-first
 * re-execute denies an unauthorized caller exactly as on every re-run tick.
 */
#[AsService]
#[SatisfiesServiceContract(of: SubscriptionFactoryInterface::class)]
final class PipelineSubscriptionFactory implements SubscriptionFactoryInterface
{
    #[InjectAsReadonly]
    protected RouteRegistry $routeRegistry;

    /**
     * The handler registry holder. Without it a resolved route carries no
     * handlers and the re-run invokes nothing → an empty frame; with it the
     * re-run re-runs THIS feed's handler. Mirrors {@see AbstractSseFeedHandler}.
     */
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    /** Test seam — production path uses property injection. */
    public function withDiscovery(RouteRegistry $routeRegistry, AttributeDiscovery $attributeDiscovery): self
    {
        $this->routeRegistry = $routeRegistry;
        $this->attributeDiscovery = $attributeDiscovery;
        return $this;
    }

    public function build(
        string $sessionId,
        string $streamingId,
        string $routePath,
        string $routeMethod,
        array $requestSnapshot,
        ?string $tenantId = null,
        ?string $tenantBlob = null,
    ): ?SubscriptionAttachment {
        $route = $this->routeRegistry->findRouteTyped(
            $routePath,
            $routeMethod !== '' ? $routeMethod : 'GET',
            $this->attributeDiscovery->getHandlerRegistry(),
        );
        if ($route === null) {
            return null;
        }

        $dtoClass = $route->requestClass;
        if ($dtoClass === '' || !class_exists($dtoClass)) {
            return null;
        }

        $request = self::rebuildRequest($requestSnapshot);

        /** @var object $dto */
        $dto = new $dtoClass();
        $dto = PayloadHydrator::hydrate($dto, $request);
        // The feed reads transport metadata + dynamic scopes off the request.
        if (method_exists($dto, 'setHttpRequest')) {
            $dto->setHttpRequest($request);
        }

        // Prefer the tenant the KISS connection captured at connect time (passed
        // by the control handler from the per-session state): it is authoritative
        // and independent of which coroutine drains this control. Fall back to the
        // current coroutine's tenant when not supplied (the standalone path / tests),
        // which is correct whenever build() runs in the connection's own coroutine.
        $record = new SubscriptionRecord(
            streamingId: $streamingId,
            sessionId: $sessionId,
            tenantId: $tenantId ?? self::currentTenantId(),
            scopeKeys: self::resolveScopeKeys($dto),
            tenantBlob: $tenantBlob ?? self::currentTenantBlob(),
        );

        // tenantContext is INTENTIONALLY null (same as the standalone re-run):
        // R4 re-runs in the coroutine that already holds the open fd, whose tenant
        // context is established and immutable for the request's life. The record
        // carries the tenant id/blob (read-only) for cross-worker channel scoping.
        $context = new ReRunContext(
            cachedDto: $dto,
            route: $route,
            requestSnapshot: $requestSnapshot,
            sessionId: $sessionId,
            subjectRef: self::currentSubjectRef(),
            tenantContext: null,
        );

        return new SubscriptionAttachment($record, $context, self::errorEventTypeFor($dto));
    }

    /**
     * The wire error event a denial/failure for this feed must carry, so the
     * client demux routes it to the feed's own typed error listener. Keyed off
     * the same marker interfaces the abstract feed handlers dispatch on, so the
     * denial channel matches the data channel the subscriber listens on.
     */
    private static function errorEventTypeFor(object $dto): string
    {
        if ($dto instanceof SseDocumentFeedPayloadInterface) {
            return UiSseEventType::UiDocumentError->value;
        }
        if ($dto instanceof SseCollectionFeedPayloadInterface) {
            return UiSseEventType::UiCollectionError->value;
        }

        return UiSseEventType::UiError->value;
    }

    /**
     * Rebuild a {@see Request} from the serialized snapshot — field-for-field the
     * same shape {@see ReRunContext::rebuildRequest()} produces, so the re-hydrated
     * DTO and the re-run's request agree.
     *
     * @param array<string, mixed> $s
     */
    private static function rebuildRequest(array $s): Request
    {
        return new Request(
            method: is_string($s['method'] ?? null) ? $s['method'] : 'GET',
            uri: is_string($s['uri'] ?? null) ? $s['uri'] : '/',
            headers: is_array($s['headers'] ?? null) ? $s['headers'] : [],
            query: is_array($s['query'] ?? null) ? $s['query'] : [],
            post: is_array($s['post'] ?? null) ? $s['post'] : [],
            server: is_array($s['server'] ?? null) ? $s['server'] : [],
            cookies: is_array($s['cookies'] ?? null) ? $s['cookies'] : [],
            content: is_string($s['content'] ?? null) ? $s['content'] : null,
            files: is_array($s['files'] ?? null) ? $s['files'] : [],
        );
    }

    /**
     * The watched scope keys: the payload's static `#[WatchScopes]` UNIONed with
     * any request-time scopes via {@see DynamicallyScopedFeedInterface}. Mirrors
     * {@see AbstractSseFeedHandler::resolveScopeKeys()}.
     *
     * @return list<string>
     */
    private static function resolveScopeKeys(object $dto): array
    {
        $scopes = AbstractSseFeedHandler::watchScopesOf($dto::class);

        if ($dto instanceof DynamicallyScopedFeedInterface) {
            foreach ($dto->dynamicWatchScopes() as $scope) {
                if (is_string($scope) && $scope !== '' && !in_array($scope, $scopes, true)) {
                    $scopes[] = $scope;
                }
            }
        }

        return array_values($scopes);
    }

    /** Tenant discriminator, defensively (mirrors the standalone handler). */
    private static function currentTenantId(): string
    {
        $tenant = self::resolveTenant();
        if (is_object($tenant) && method_exists($tenant, 'getTenantId')) {
            $id = trim((string) $tenant->getTenantId());
            if ($id !== '') {
                return $id;
            }
        }

        return 'default';
    }

    /** Opaque serialized tenant context for cross-worker channel scoping. */
    private static function currentTenantBlob(): string
    {
        $tenant = self::resolveTenant();
        $blob = null;
        if (is_object($tenant) && method_exists($tenant, 'forSerialization')) {
            $blob = $tenant->forSerialization();
        }

        try {
            return json_encode(is_array($blob) ? $blob : [], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '[]';
        }
    }

    private static function resolveTenant(): ?object
    {
        $ctx = '\Semitexa\Tenancy\Context\TenantContext';
        if (class_exists($ctx) && method_exists($ctx, 'get')) {
            $tenant = $ctx::get();

            return is_object($tenant) ? $tenant : null;
        }

        return null;
    }

    /**
     * The frozen subject reference (the re-auth anchor) — the page's user on THIS
     * coroutine, best-effort. Mirrors {@see AbstractSseFeedHandler}; '' when no
     * subject is resolvable (e.g. a signed-ctx feed with no session user).
     */
    private static function currentSubjectRef(): string
    {
        $store = '\Semitexa\Auth\Context\AuthContextStore';
        if (class_exists($store) && method_exists($store, 'getUser')) {
            /** @var object|null $user */
            $user = $store::getUser();
            if (is_object($user) && method_exists($user, 'getId')) {
                return (string) $user->getId();
            }
        }

        return '';
    }
}
