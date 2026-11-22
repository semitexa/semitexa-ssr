<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Closure;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthBootstrapperFactoryInterface;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Pipeline\PayloadAdmission;
use Semitexa\Core\Pipeline\PipelineExecutor;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;

/**
 * The page's own access policy, asked again when its deferred slots are drawn
 * through `GET /__semitexa_hug?slots=…` (tk-ssr-hug-deferred-slots-auth-bypass).
 *
 * That route is public and a slot handler has no request, so a slot of a
 * protected page was drawn for anyone who named its handle. A slot is now as
 * open as a page that renders it: the routes answering with the resource that
 * declares the handle are found, and the request passes one of them as a direct
 * request would — the pre-hydration auth gate and the AuthCheck phase, with the
 * hug request's identity. The page's payload stays bare: the hug request carries
 * none of its fields, and nothing in the page's handler runs.
 *
 * Nothing here reads the deferred request, so the answer is the same after it
 * expired: a public page's slots still draw, a protected page's still ask who
 * is there. A request that does hold its page's bind cookie is one the page was
 * already served to ($served): it passes as a re-run does, uncounted by a rate
 * limit and without the signature of a signed link, which a hug URL cannot carry.
 *
 * Worker singleton: every request value travels through arguments.
 */
#[AsService]
final class DeferredSlotAccessGate
{
    #[InjectAsReadonly]
    protected RouteRegistry $routeRegistry;

    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** @var array<string, list<DiscoveredRoute>>|null handle => its routes, public ones first; read once per worker */
    private ?array $owners = null;

    /** @var (Closure(DiscoveredRoute, Request, bool): void)|null test seam */
    private ?Closure $admitter = null;

    /**
     * @param bool $served the request holds the bind cookie of a live deferred request of this page
     * @throws NotFoundException no route answers with this handle, and the page was not served to this browser
     * @throws \Throwable what the page's own gate or AuthCheck listener throws (401/403)
     */
    public function admit(string $pageHandle, Request $request, bool $served): void
    {
        $routes = $this->owners()[strtolower($pageHandle)] ?? [];
        if ($routes === []) {
            // A handle no route declares (set in a handler, or a page drawn
            // without a route) has no policy to ask: the bind cookie is the
            // only proof left that somebody was let onto that page.
            if ($served) {
                return;
            }

            throw new NotFoundException('Page handle', $pageHandle);
        }

        $refusal = null;
        foreach ($routes as $route) {
            try {
                $this->admitTo($route, $request, $served);

                return;
            } catch (\Throwable $e) {
                // The slot draws on every page of the handle: one that admits is enough.
                $refusal ??= $e;
            }
        }

        throw $refusal;
    }

    private function admitTo(DiscoveredRoute $route, Request $request, bool $served): void
    {
        if ($this->admitter !== null) {
            ($this->admitter)($route, $request, $served);

            return;
        }

        // Built through its factory, as the application builds it (it is not a
        // container service), over the request scope this hug request runs in.
        $scope = RequestScopedContainer::forCurrentExecution($this->container);
        $factory = $this->container->has(AuthBootstrapperFactoryInterface::class)
            ? $this->container->get(AuthBootstrapperFactoryInterface::class)
            : null;
        $bootstrapper = $factory instanceof AuthBootstrapperFactoryInterface ? $factory->create($this->container, $scope) : null;

        // As an OPTIONS probe is admitted: the bare payload and the gate, no hydration.
        $payload = (new PayloadAdmission($this->container, $bootstrapper))->gateAndHydrate($route, $request, true, null);

        $context = new RequestPipelineContext(
            requestDto: $payload,
            route: $route,
            request: $request,
            authBootstrapper: $bootstrapper,
        );
        $context->reRun = $served;
        (new PipelineExecutor($scope, $this->container))->authorize($context);
    }

    /** @return array<string, list<DiscoveredRoute>> */
    private function owners(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }

        $handlers = $this->attributeDiscovery->getHandlerRegistry();
        $owners = [];
        foreach ($this->routeRegistry->getAll() as $raw) {
            $requestClass = is_string($raw['class'] ?? null) ? $raw['class'] : '';
            $responseClass = is_string($raw['responseClass'] ?? null) ? $raw['responseClass'] : null;
            // A listener may read the route's handlers (a tenant-isolated one).
            $raw['handlers'] = $handlers->findHandlers($requestClass, $responseClass);
            $route = DiscoveredRoute::fromArray($raw);

            $handles = [];
            foreach ([$route->responseClass, ...array_values($route->responsesByProfile ?? [])] as $class) {
                $handle = is_string($class) ? self::handleOf($class) : null;
                if ($handle !== null) {
                    $handles[$handle] = true;
                }
            }
            foreach (array_keys($handles) as $handle) {
                $owners[$handle][] = $route;
            }
        }
        foreach ($owners as &$routes) {
            usort($routes, static fn (DiscoveredRoute $a, DiscoveredRoute $b): int
                => ($a->accessType === PayloadAccessType::Public ? 0 : 1) <=> ($b->accessType === PayloadAccessType::Public ? 0 : 1));
        }
        unset($routes);

        return $this->owners = $owners;
    }

    /** The #[AsResource] handle of a resource class or of one it extends, lower-cased as the slot registry keys it. */
    private static function handleOf(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }
        for ($ref = new \ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
            $attribute = $ref->getAttributes(AsResource::class)[0] ?? null;
            if ($attribute !== null) {
                $handle = $attribute->newInstance()->handle;

                return is_string($handle) && $handle !== '' ? strtolower($handle) : null;
            }
        }

        return null;
    }

    /**
     * Test seam: discovery and the admission step.
     *
     * @param (Closure(DiscoveredRoute, Request, bool): void)|null $admitter null runs the real admission
     */
    public function withCollaborators(RouteRegistry $routes, AttributeDiscovery $discovery, ?Closure $admitter = null, ?ContainerInterface $container = null): self
    {
        $this->routeRegistry = $routes;
        $this->attributeDiscovery = $discovery;
        $this->admitter = $admitter;
        if ($container !== null) {
            $this->container = $container;
        }
        $this->owners = null;

        return $this;
    }
}
