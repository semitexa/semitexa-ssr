<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Stream;

use Closure;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Domain\Contract\FeedStreamSinkInterface;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * Feed control on HUG: `POST /__semitexa_hug {"stream": {op, feed, params,
 * session, subscriptionId}}` attaches a feed to the page's KISS stream, changes
 * its view, or detaches it. Frames only ever travel on KISS; this answers with
 * an acknowledgement, never rows.
 *
 * The feed is addressed by its route NAME, and a subscribe or view change is
 * admitted exactly as a direct request would be: a GET to the feed route built
 * from the HUG request's identity (headers, cookies) with `params` as its query,
 * through {@see RouteExecutor::admit()} — the auth gate, hydration, validation
 * and AuthCheck. The outer HUG POST already passed CSRF. The worker that owns
 * the KISS connection then refuses an attach from another tenant.
 *
 * Worker singleton: every request value travels through arguments.
 */
#[AsService]
final class FeedStreamControl
{
    public const OPS = ['subscribe', 'unsubscribe', 'view'];

    #[InjectAsReadonly]
    protected RouteRegistry $routeRegistry;

    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    #[InjectAsReadonly]
    protected SseServer $sseServer;

    /** @var (Closure(DiscoveredRoute, Request): object)|null test seam */
    private ?Closure $admitter = null;

    private ?FeedStreamSinkInterface $sink = null;

    /**
     * @param array<array-key, mixed> $fields the `stream` object of the HUG body
     * @return array{0: int, 1: array<string, mixed>} [status, body]
     * @throws ValidationException on a malformed control (422)
     * @throws \Throwable whatever the feed's own admission throws (401/403/422)
     */
    public function control(array $fields, Request $hugRequest): array
    {
        $control = self::validate($fields);

        $route = $this->routeRegistry->findByNameTyped($control['feed'], $this->attributeDiscovery->getHandlerRegistry());
        // One answer for "no such route" and "not a feed": the name space of
        // ordinary routes is not discoverable through this door.
        if ($route === null || $route->transport !== TransportType::Sse->value
            || !is_subclass_of($route->requestClass, SseFeedPayloadInterface::class)) {
            return [404, ['ok' => false, 'accepted' => false, 'reason' => 'unknown_feed']];
        }

        $accepted = match ($control['op']) {
            'unsubscribe' => $this->sse()->submitUnsubscribe($control['session'], $control['subscriptionId']),
            'subscribe' => $this->subscribe($route, $control, $hugRequest),
            'view' => $this->view($route, $control, $hugRequest),
        };

        return $accepted
            ? [202, ['ok' => true, 'accepted' => true, 'subscription_id' => $control['subscriptionId']]]
            : [400, ['ok' => false, 'accepted' => false, 'reason' => 'invalid_session']];
    }

    /** @param array{op: string, feed: string, params: array<string, scalar|null>, session: string, subscriptionId: string} $control */
    private function subscribe(DiscoveredRoute $route, array $control, Request $hugRequest): bool
    {
        $feedRequest = self::feedRequest($route, $control['params'], $hugRequest);
        $this->admit($route, $feedRequest);

        return $this->sse()->submitSubscribe(
            $control['session'],
            $control['subscriptionId'],
            $route->path,
            'GET',
            self::snapshot($feedRequest),
            (string) $route->name,
        );
    }

    /** @param array{op: string, feed: string, params: array<string, scalar|null>, session: string, subscriptionId: string} $control */
    private function view(DiscoveredRoute $route, array $control, Request $hugRequest): bool
    {
        $payload = $this->admit($route, self::feedRequest($route, $control['params'], $hugRequest));
        if (!$payload instanceof SseFeedPayloadInterface) {
            return false;
        }

        return $this->sse()->submitViewChange($control['session'], $payload->toViewParams(), $control['subscriptionId']);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return array{op: string, feed: string, params: array<string, scalar|null>, session: string, subscriptionId: string}
     */
    private static function validate(array $fields): array
    {
        $errors = [];
        $allowed = ['op', 'feed', 'params', 'session', 'subscriptionId'];
        foreach (array_keys($fields) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors['stream.' . $key] = ['Unknown stream control field.'];
            }
        }

        $op = $fields['op'] ?? null;
        if (!is_string($op) || !in_array($op, self::OPS, true)) {
            $errors['stream.op'] = ['Must be one of: ' . implode(', ', self::OPS) . '.'];
        }
        $feed = $fields['feed'] ?? null;
        if (!is_string($feed) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $feed) !== 1) {
            $errors['stream.feed'] = ['Must be a feed route name.'];
        }
        foreach (['session', 'subscriptionId'] as $key) {
            $value = $fields[$key] ?? null;
            if (!is_string($value) || preg_match(SseServer::SAFE_BEARER_SESSION_ID_PATTERN, $value) !== 1) {
                $errors['stream.' . $key] = ['Must be an sse_<32 hex> id.'];
            }
        }
        $params = $fields['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            $errors['stream.params'] = ['Must be an object of flat scalar values.'];
            $params = [];
        }
        foreach ($params as $name => $value) {
            if (!is_string($name) || !(is_scalar($value) || $value === null)) {
                $errors['stream.params.' . $name] = ['Must be a scalar value.'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var array{op: string, feed: string, params: array<string, scalar|null>, session: string, subscriptionId: string} */
        return ['op' => $op, 'feed' => $feed, 'params' => $params, 'session' => $fields['session'], 'subscriptionId' => $fields['subscriptionId']];
    }

    /**
     * The GET the feed would have received directly: the HUG request's identity
     * (headers, cookies, server) and the control's params as its query.
     *
     * @param array<string, scalar|null> $params
     */
    private static function feedRequest(DiscoveredRoute $route, array $params, Request $hugRequest): Request
    {
        $path = $route->path !== '' ? $route->path : '/__semitexa_hug';
        $query = $params === [] ? '' : '?' . http_build_query($params);
        $headers = [];
        foreach ($hugRequest->headers as $name => $value) {
            if (!in_array(strtolower((string) $name), ['content-type', 'content-length'], true)) {
                $headers[$name] = $value;
            }
        }

        return new Request(
            method: 'GET',
            uri: $path . $query,
            headers: $headers,
            query: $params,
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path . $query] + $hugRequest->server,
            cookies: $hugRequest->cookies,
            content: '',
            files: [],
        );
    }

    /** @return array<string, mixed> the snapshot the owning worker rebuilds the feed request from */
    private static function snapshot(Request $request): array
    {
        return [
            'method' => $request->method,
            'uri' => $request->uri,
            'headers' => $request->headers,
            'query' => $request->query,
            'post' => $request->post,
            'server' => $request->server,
            'cookies' => $request->cookies,
            'content' => $request->content,
            'files' => $request->files,
        ];
    }

    private function admit(DiscoveredRoute $route, Request $request): object
    {
        if ($this->admitter !== null) {
            return ($this->admitter)($route, $request);
        }
        $auth = $this->container->has(AuthBootstrapperInterface::class)
            ? $this->container->get(AuthBootstrapperInterface::class)
            : null;

        return (new RouteExecutor(
            RequestScopedContainer::forCurrentExecution($this->container),
            $this->container,
            $auth instanceof AuthBootstrapperInterface ? $auth : null,
        ))->admit($route, $request);
    }

    private function sse(): FeedStreamSinkInterface
    {
        return $this->sink ?? $this->sseServer;
    }

    /**
     * Test seam: discovery, the admission step and the stream sink.
     *
     * @param Closure(DiscoveredRoute, Request): object $admitter
     */
    public function withCollaborators(RouteRegistry $routes, AttributeDiscovery $discovery, Closure $admitter, FeedStreamSinkInterface $sink): self
    {
        $this->routeRegistry = $routes;
        $this->attributeDiscovery = $discovery;
        $this->admitter = $admitter;
        $this->sink = $sink;

        return $this;
    }
}
