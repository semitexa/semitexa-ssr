<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Service\Stream\FeedStreamControl;
use Semitexa\Ssr\Domain\Contract\FeedStreamSinkInterface;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * Feed control on HUG: a named feed, admitted as the GET it would have been,
 * handed to the KISS server — and nothing else gets through.
 */
final class FeedStreamControlTest extends TestCase
{
    private const SESSION = 'sse_0123456789abcdef0123456789abcdef';
    private const SUB = 'sse_fedcba9876543210fedcba9876543210';

    private RecordingFeedSink $sink;
    /** @var list<Request> */
    private array $admitted = [];
    private ?\Throwable $admissionFailure = null;

    #[Test]
    public function a_subscribe_is_admitted_as_a_get_to_the_feed_and_handed_to_kiss_by_name(): void
    {
        [$status, $body] = $this->control()->control($this->fields('subscribe', ['q' => 'acme', 'page' => 2]), $this->hugRequest());

        self::assertSame(202, $status);
        self::assertSame(['ok' => true, 'accepted' => true, 'subscription_id' => self::SUB], $body);

        $request = $this->admitted[0];
        self::assertSame('GET', $request->method);
        self::assertSame('/leads/feed?q=acme&page=2', $request->uri);
        self::assertSame(['q' => 'acme', 'page' => 2], $request->query);
        // The identity the HUG request carried is the identity admitted.
        self::assertSame(['session' => 'cookie-value'], $request->cookies);
        self::assertArrayNotHasKey('Content-Type', $request->headers);

        $call = $this->sink->calls[0];
        self::assertSame('subscribe', $call[0]);
        self::assertSame([self::SESSION, self::SUB, '/leads/feed', 'GET'], array_slice($call[1], 0, 4));
        self::assertSame('leads.feed', $call[1][5], 'the owning worker resolves the feed by name');
        self::assertSame('/leads/feed?q=acme&page=2', $call[1][4]['uri']);
    }

    #[Test]
    public function a_view_change_submits_the_admitted_payloads_view(): void
    {
        [$status] = $this->control()->control($this->fields('view', ['q' => 'b']), $this->hugRequest());

        self::assertSame(202, $status);
        self::assertSame(['view', [self::SESSION, ['q' => 'b'], self::SUB]], $this->sink->calls[0]);
    }

    #[Test]
    public function an_unsubscribe_detaches_without_admission(): void
    {
        [$status] = $this->control()->control($this->fields('unsubscribe'), $this->hugRequest());

        self::assertSame(202, $status);
        self::assertSame([], $this->admitted);
        self::assertSame(['unsubscribe', [self::SESSION, self::SUB]], $this->sink->calls[0]);
    }

    #[Test]
    public function an_unknown_name_and_a_route_that_is_not_a_feed_get_the_same_404(): void
    {
        foreach (['no.such.route', 'plain.page'] as $feed) {
            [$status, $body] = $this->control()->control(['feed' => $feed] + $this->fields('subscribe'), $this->hugRequest());
            self::assertSame(404, $status, $feed);
            self::assertSame('unknown_feed', $body['reason'], $feed);
        }
        self::assertSame([], $this->sink->calls);
        self::assertSame([], $this->admitted);
    }

    #[Test]
    public function a_refused_admission_attaches_nothing(): void
    {
        $this->admissionFailure = new AccessDeniedException('nope');

        try {
            $this->control()->control($this->fields('subscribe'), $this->hugRequest());
            self::fail('admission refusal must propagate');
        } catch (AccessDeniedException) {
        }
        self::assertSame([], $this->sink->calls);
    }

    #[Test]
    public function a_malformed_control_reports_every_bad_field(): void
    {
        try {
            $this->control()->control([
                'op' => 'drop', 'feed' => '../x', 'session' => 'abc', 'subscriptionId' => 7,
                'params' => ['nested' => ['x' => 1]], 'route' => '/admin',
            ], $this->hugRequest());
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            foreach (['stream.op', 'stream.feed', 'stream.session', 'stream.subscriptionId', 'stream.params.nested', 'stream.route'] as $key) {
                self::assertArrayHasKey($key, $errors, $key);
            }
        }
        self::assertSame([], $this->sink->calls);
    }

    #[Test]
    public function a_rejected_session_is_a_400(): void
    {
        $this->sink->accept = false;
        [$status, $body] = $this->control()->control($this->fields('unsubscribe'), $this->hugRequest());

        self::assertSame(400, $status);
        self::assertSame('invalid_session', $body['reason']);
    }

    protected function setUp(): void
    {
        $this->sink = new RecordingFeedSink();
    }

    private function control(): FeedStreamControl
    {
        $routes = new RouteRegistry();
        $routes->register(['path' => '/leads/feed', 'methods' => ['GET', 'POST'], 'name' => 'leads.feed', 'class' => FixtureFeedPayload::class, 'transport' => 'sse']);
        $routes->register(['path' => '/page', 'methods' => ['GET'], 'name' => 'plain.page', 'class' => \stdClass::class, 'transport' => 'http']);

        return (new FeedStreamControl())->withCollaborators(
            $routes,
            new AttributeDiscovery(new ClassDiscovery(), new ModuleRegistry(), new RouteRegistry()),
            function (DiscoveredRoute $route, Request $request): object {
                if ($this->admissionFailure !== null) {
                    throw $this->admissionFailure;
                }
                $this->admitted[] = $request;
                return new FixtureFeedPayload($request->query);
            },
            $this->sink,
        );
    }

    /**
     * @param array<string, scalar> $params
     * @return array<string, mixed>
     */
    private function fields(string $op, array $params = []): array
    {
        return ['op' => $op, 'feed' => 'leads.feed', 'params' => $params, 'session' => self::SESSION, 'subscriptionId' => self::SUB];
    }

    private function hugRequest(): Request
    {
        return new Request(
            method: 'POST',
            uri: '/__semitexa_hug',
            headers: ['Content-Type' => 'application/json', 'Host' => 'acme.test'],
            query: [],
            post: [],
            server: [],
            cookies: ['session' => 'cookie-value'],
            content: '{}',
        );
    }
}

final class RecordingFeedSink implements FeedStreamSinkInterface
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];
    public bool $accept = true;

    public function submitSubscribe(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot, string $routeName = '', ?string $requesterTenantId = null): bool
    {
        $this->calls[] = ['subscribe', [$sessionId, $streamingId, $routePath, $routeMethod, $requestSnapshot, $routeName]];
        return $this->accept;
    }

    public function submitViewChange(string $sessionId, array $params, ?string $streamingId = null): bool
    {
        $this->calls[] = ['view', [$sessionId, $params, $streamingId]];
        return $this->accept;
    }

    public function submitUnsubscribe(string $sessionId, string $streamingId): bool
    {
        $this->calls[] = ['unsubscribe', [$sessionId, $streamingId]];
        return $this->accept;
    }
}

final class FixtureFeedPayload implements SseFeedPayloadInterface
{
    /** @param array<string, mixed> $view */
    public function __construct(private readonly array $view = []) {}

    public function getHttpRequest(): ?Request
    {
        return null;
    }

    public function getStreamId(): ?string
    {
        return null;
    }

    public function toViewParams(): array
    {
        return $this->view;
    }
}
