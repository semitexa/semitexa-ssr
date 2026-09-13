<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Support\Row;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Http\PayloadFactory;
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

    /**
     * For {@see PropertyInjector::inject()} on the rebuilt request DTO — the
     * same collaborator RouteExecutor uses for the ordinary request path.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** Test seam — production path uses property injection. */
    /**
     * The test seam: supply the collaborators instead of being injected.
     *
     * `$container` is optional because the parameter is new and this method is
     * not — but build() needs it to give a payload its #[InjectAsReadonly]
     * dependencies, so a seam that omits it gets a payload without them. That
     * is the seam's own limit, stated here rather than discovered at the first
     * uninitialized-property Error.
     */
    public function withDiscovery(
        RouteRegistry $routeRegistry,
        AttributeDiscovery $attributeDiscovery,
        ?ContainerInterface $container = null,
    ): self {
        $this->routeRegistry = $routeRegistry;
        $this->attributeDiscovery = $attributeDiscovery;
        if ($container !== null) {
            $this->container = $container;
        }
        return $this;
    }

    /**
     * The injected container, or null when there is none.
     *
     * isset(), not a null check: the property is UNINITIALIZED when this
     * factory is built through withDiscovery() rather than by the container,
     * and reading it directly throws instead of falling through.
     */
    private function container(): ?ContainerInterface
    {
        return isset($this->container) ? $this->container : null;
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

        // Built the way the ordinary request path builds it — see
        // RouteExecutor::createBarePayload(): payload PARTS first, then the
        // instance, then injection, and only then hydration.
        //
        // `new $dtoClass()` skipped the first two. A payload assembled from
        // #[AsPayloadPart] traits lost every one of them on a re-run, and a
        // payload with #[InjectAsReadonly] dependencies came back with those
        // properties UNINITIALIZED — so an SSE feed that worked on the first
        // request threw on the first re-run, in a coroutine far from the cause.
        $container = $this->container();
        $traits = $this->payloadPartRegistry($container)->getPayloadPartsForClass($dtoClass);
        $dto = PayloadFactory::createInstance($dtoClass, $traits);
        if ($container !== null) {
            PropertyInjector::inject($dto, $container);
        }

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
            scopeKeys: self::resolveScopeKeys($dtoClass, $dto),
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
     * The payload-part registry, preferred from the container and falling back
     * to discovery — the same order RouteExecutor uses, so a re-run assembles
     * a payload from exactly the parts the first request did.
     */
    private function payloadPartRegistry(?ContainerInterface $container): PayloadPartRegistry
    {
        if ($container !== null && $container->has(PayloadPartRegistry::class)) {
            /** @var PayloadPartRegistry $registry */
            $registry = $container->get(PayloadPartRegistry::class);

            return $registry;
        }

        return $this->attributeDiscovery->getPayloadPartRegistry();
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
     * `$declaredClass` is the class the ROUTE names, NOT `$dto::class`.
     *
     * Once payloads are built through PayloadFactory, a payload that declares
     * #[AsPayloadPart] traits comes back as a generated wrapper extending the
     * base — and PHP attributes are not inherited: `getAttributes()` on the
     * wrapper returns nothing. Reading the runtime class would have handed
     * watchScopesOf() a class with no #[WatchScopes] on it, so the subscription
     * would carry NO scope keys and the feed would quietly stop receiving
     * invalidations. Nothing would have thrown.
     *
     * @param class-string $declaredClass
     * @return list<string>
     */
    private static function resolveScopeKeys(string $declaredClass, object $dto): array
    {
        $scopes = AbstractSseFeedHandler::watchScopesOf($declaredClass);

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
            $id = trim(Row::asString($tenant->getTenantId()));
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
                return Row::asString($user->getId());
            }
        }

        return '';
    }
}
