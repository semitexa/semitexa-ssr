<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Handler\PayloadHandler\HugEventHandler;
use Semitexa\Ssr\Application\Payload\Request\HugEventPayload;
use Semitexa\Ssr\Application\Service\UiEvent\NotConfiguredUiResponseDispatcher;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatcherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatchResult;

final class HugEventHandlerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'APP_SECRET' => getenv('APP_SECRET'),
            'APP_ENV' => getenv('APP_ENV'),
        ];

        $_ENV['APP_SECRET'] = 'test-secret-' . bin2hex(random_bytes(8));
        putenv('APP_SECRET=' . $_ENV['APP_SECRET']);
        $_ENV['APP_ENV'] = 'test';
        putenv('APP_ENV=test');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function validBody(string $signedContext, array $overrides = []): array
    {
        return array_replace([
            'schemaVersion' => UiEventEnvelope::SCHEMA_VERSION,
            'eventId' => 'evt_test',
            'correlationId' => 'corr_test',
            'semanticEvent' => 'click',
            'signedContext' => $signedContext,
            'timestamp' => '2026-05-11T10:00:00Z',
            'payload' => [],
            'transport' => ['kind' => 'http'],
            'primitiveName' => 'platform.button',
            'primitiveUi' => 'button',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): Request
    {
        return new Request(
            method: 'POST',
            uri: '/__semitexa_hug',
            headers: ['content-type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function handlerFor(Request $request, ?UiResponseDispatcherInterface $dispatcher = null): HugEventHandler
    {
        // Default to the framework's not-configured dispatcher — that's
        // exactly what production wiring resolves when no platform
        // package overrides the contract, so it's the right baseline
        // for "no test seam injected" calls.
        $handler = (new HugEventHandler())->withRequest($request);
        $handler->withDispatcher($dispatcher ?? new NotConfiguredUiResponseDispatcher());
        return $handler;
    }

    #[Test]
    public function accepts_valid_envelope_with_verified_signed_context_and_default_not_configured_dispatcher(): void
    {
        // With no concrete dispatcher installed, the endpoint must still
        // accept-and-respond rather than crash: the framework default is
        // NotConfiguredUiResponseDispatcher, returning a stable
        // `accepted / foundation / dispatcher_not_configured` envelope.
        $signed = SignedContext::sign(['ctx' => 'valid'], 60);
        $resource = $this->handlerFor($this->postRequest($this->validBody($signed)))
            ->handle(new HugEventPayload(), new ResourceResponse());

        self::assertSame(202, $resource->getStatusCode());
        $body = json_decode($resource->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('accepted', $body['status']);
        self::assertSame('foundation', $body['phase']);
        self::assertSame('dispatcher_not_configured', $body['reason']);
        self::assertSame('UI event endpoint is active, but no UI response dispatcher is installed.', $body['message']);
        self::assertSame('evt_test', $body['eventId']);
        self::assertSame('corr_test', $body['correlationId']);
        self::assertSame('click', $body['semanticEvent']);
        self::assertSame(UiEventEnvelope::SCHEMA_VERSION, $body['schemaVersion']);
        self::assertTrue($body['signedContext']['verified']);
        // The handler MUST NOT emit the legacy `resolution`/`not_implemented`
        // path any more — that lived only while the endpoint was a stub.
        self::assertArrayNotHasKey('resolution', $body);
    }

    #[Test]
    public function delegates_to_injected_dispatcher_with_envelope_and_verified_claims(): void
    {
        // Pin the contract: the endpoint hands the dispatcher the
        // already-validated envelope AND the verified claims array (not
        // the raw blob, not null). A dispatcher must never have to re-
        // verify the signed context.
        $signed = SignedContext::sign(['who' => 'alice', 'lvl' => 7], 60);

        $recording = new class () implements UiResponseDispatcherInterface {
            public ?UiEventEnvelope $envelope = null;
            /** @var array<string, mixed>|null */
            public ?array $claims = null;

            public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult
            {
                $this->envelope = $envelope;
                $this->claims   = $verifiedClaims;
                return new UiResponseDispatchResult(
                    statusCode: 200,
                    status:     'accepted',
                    phase:      'dispatch',
                    reason:     'recorded',
                    message:    'ok',
                );
            }
        };

        $resource = $this->handlerFor($this->postRequest($this->validBody($signed)), $recording)
            ->handle(new HugEventPayload(), new ResourceResponse());

        self::assertSame(200, $resource->getStatusCode());
        self::assertInstanceOf(UiEventEnvelope::class, $recording->envelope);
        self::assertSame('evt_test', $recording->envelope->eventId);
        self::assertIsArray($recording->claims);
        self::assertSame('alice', $recording->claims['who'] ?? null);
        self::assertSame(7, $recording->claims['lvl'] ?? null);

        $body = json_decode($resource->getContent(), true);
        self::assertSame('accepted', $body['status']);
        self::assertSame('dispatch', $body['phase']);
        self::assertSame('recorded', $body['reason']);
    }

    #[Test]
    public function dispatcher_result_body_is_folded_in_but_cannot_overwrite_reserved_keys(): void
    {
        // Defence in depth: the dispatcher MAY add free-form fields (e.g.
        // patch list, correlation hints, debug echo) via $result->body,
        // but MUST NOT be able to rewrite the canonical envelope keys.
        $signed = SignedContext::sign(['x' => 1], 60);

        $hostile = new class () implements UiResponseDispatcherInterface {
            public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult
            {
                return new UiResponseDispatchResult(
                    statusCode: 200,
                    status:     'accepted',
                    phase:      'dispatch',
                    reason:     'extra_fields',
                    message:    'ok',
                    body: [
                        // free-form: should land in the response
                        'patches'    => [['op' => 'setText', 'target' => 'x', 'value' => 'y']],
                        'debug'      => ['note' => 'hello'],
                        // hostile attempts to rewrite the canonical envelope
                        'status'        => 'ROOTED',
                        'phase'         => 'ROOTED',
                        'reason'        => 'ROOTED',
                        'message'       => 'ROOTED',
                        'eventId'       => 'ROOTED',
                        'correlationId' => 'ROOTED',
                        'semanticEvent' => 'ROOTED',
                        'schemaVersion' => 999,
                        'signedContext' => ['present' => false, 'verified' => false],
                    ],
                );
            }
        };

        $resource = $this->handlerFor($this->postRequest($this->validBody($signed)), $hostile)
            ->handle(new HugEventPayload(), new ResourceResponse());

        $body = json_decode($resource->getContent(), true);
        // canonical keys preserved
        self::assertSame('accepted', $body['status']);
        self::assertSame('dispatch', $body['phase']);
        self::assertSame('extra_fields', $body['reason']);
        self::assertSame('ok', $body['message']);
        self::assertSame('evt_test', $body['eventId']);
        self::assertSame('corr_test', $body['correlationId']);
        self::assertSame('click', $body['semanticEvent']);
        self::assertSame(UiEventEnvelope::SCHEMA_VERSION, $body['schemaVersion']);
        self::assertTrue($body['signedContext']['verified']);
        // free-form keys folded in
        self::assertSame([['op' => 'setText', 'target' => 'x', 'value' => 'y']], $body['patches']);
        self::assertSame(['note' => 'hello'], $body['debug']);
    }

    #[Test]
    public function dispatcher_throw_is_translated_into_a_safe_error_envelope(): void
    {
        // A dispatcher is allowed to throw; the endpoint MUST NOT leak
        // the throwable's class / message / file / stack to the caller.
        // The translation is deterministic: status 500, error/dispatch,
        // reason ui_event_dispatcher_failure, generic message.
        $signed = SignedContext::sign(['x' => 1], 60);

        $thrower = new class () implements UiResponseDispatcherInterface {
            public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult
            {
                throw new \RuntimeException('CANARY_SECRET: should not surface');
            }
        };

        $resource = $this->handlerFor($this->postRequest($this->validBody($signed)), $thrower)
            ->handle(new HugEventPayload(), new ResourceResponse());

        self::assertSame(500, $resource->getStatusCode());
        $body = json_decode($resource->getContent(), true);
        self::assertSame('error',  $body['status']);
        self::assertSame('dispatch', $body['phase']);
        self::assertSame('ui_event_dispatcher_failure', $body['reason']);
        self::assertSame('UI event dispatcher failed to handle the request.', $body['message']);

        // Canary check: nothing about the throwable leaks.
        $raw = (string) $resource->getContent();
        self::assertStringNotContainsString('CANARY_SECRET', $raw);
        self::assertStringNotContainsString('RuntimeException', $raw);
    }

    #[Test]
    public function non_encodable_dispatcher_body_falls_back_to_safe_error_envelope(): void
    {
        // A dispatcher MAY return a body whose values trip
        // JSON_THROW_ON_ERROR (e.g. invalid UTF-8). The encoding failure
        // must not escape handle() — the contract is the same stable
        // dispatcher-failure envelope the throwing-dispatcher path emits.
        $signed = SignedContext::sign(['x' => 1], 60);

        $brokenBody = new class () implements UiResponseDispatcherInterface {
            public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult
            {
                return new UiResponseDispatchResult(
                    statusCode: 200,
                    status:     'accepted',
                    phase:      'dispatch',
                    reason:     'recorded',
                    message:    'ok',
                    body: [
                        // Invalid UTF-8 sequence — JSON_THROW_ON_ERROR rejects it.
                        'blob' => "\xB1\x31",
                    ],
                );
            }
        };

        $resource = $this->handlerFor($this->postRequest($this->validBody($signed)), $brokenBody)
            ->handle(new HugEventPayload(), new ResourceResponse());

        self::assertSame(500, $resource->getStatusCode());
        $body = json_decode($resource->getContent(), true);
        self::assertSame('error', $body['status']);
        self::assertSame('dispatch', $body['phase']);
        self::assertSame('ui_event_dispatcher_failure', $body['reason']);
        self::assertSame('UI event dispatcher failed to handle the request.', $body['message']);
        self::assertSame('evt_test', $body['eventId']);
    }

    #[Test]
    public function dispatcher_is_not_invoked_when_signed_context_does_not_verify(): void
    {
        // Trust boundary: a tampered/invalid signed context MUST NOT
        // reach the dispatcher. Without the early reject, a malicious
        // payload could trigger dispatcher side effects under
        // unverified identity.
        $signed = SignedContext::sign(['x' => 1], 60) . 'TAMPER';

        $sentinel = new class () implements UiResponseDispatcherInterface {
            public int $calls = 0;
            public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult
            {
                $this->calls++;
                return new UiResponseDispatchResult(202, 'accepted', 'dispatch', 'ok', 'ok');
            }
        };

        try {
            $this->handlerFor($this->postRequest($this->validBody($signed)), $sentinel)
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('Tampered signed context must be rejected before the dispatcher is called.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('signedContext', $e->getErrorContext()['errors']);
        }
        self::assertSame(0, $sentinel->calls, 'Dispatcher must NEVER see a request whose signed ctx failed verification.');
    }

    #[Test]
    public function rejects_envelope_when_signed_context_does_not_verify(): void
    {
        // signedContext is the trust boundary for server-side handler
        // resolution; an unverifiable blob must NEVER take the success path.
        $signed = SignedContext::sign(['ctx' => 'valid'], 60) . 'TAMPER';

        try {
            $this->handlerFor($this->postRequest($this->validBody($signed)))
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('Tampered signed context must be rejected.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('signedContext', $e->getErrorContext()['errors']);
        }
    }

    #[Test]
    public function rejects_top_level_handler_smuggling(): void
    {
        foreach (['handler', 'handlerClass', 'handler_class', 'handlerMethod', 'handler_method', 'method', 'controller', 'action', 'callback', 'endpoint', 'route', 'url', 'backendHandler', 'backend_handler', 'payloadClass', 'payload_class', 'authzScope', 'authz_scope'] as $field) {
            $signed = SignedContext::sign(['x' => 1], 60);
            $body = $this->validBody($signed, [$field => 'Smuggled\\Backend::handle']);

            try {
                $this->handlerFor($this->postRequest($body))
                    ->handle(new HugEventPayload(), new ResourceResponse());
                self::fail("Handler should have rejected top-level '{$field}'");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getErrorContext()['errors'], "expected error key on '{$field}'");
            }
        }
    }

    #[Test]
    public function rejects_nested_handler_smuggling_inside_payload(): void
    {
        $signed = SignedContext::sign(['x' => 1], 60);
        $body = $this->validBody($signed, ['payload' => ['handler' => 'Sneaky\\Handler::go']]);

        try {
            $this->handlerFor($this->postRequest($body))
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('Handler should have rejected nested payload.handler');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('payload.handler', $e->getErrorContext()['errors']);
        }
    }

    #[Test]
    public function rejects_deeply_nested_handler_smuggling_inside_payload(): void
    {
        $signed = SignedContext::sign(['x' => 1], 60);
        $body = $this->validBody($signed, [
            'payload' => [
                'meta' => [
                    'dispatch' => [
                        'handler' => 'Sneaky\\Handler::go',
                    ],
                ],
            ],
        ]);

        try {
            $this->handlerFor($this->postRequest($body))
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('Handler should have rejected nested payload.meta.dispatch.handler');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('payload.meta.dispatch.handler', $e->getErrorContext()['errors']);
        }
    }

    #[Test]
    public function rejects_nested_handler_smuggling_inside_transport_and_metadata_and_context(): void
    {
        $signed = SignedContext::sign(['x' => 1], 60);

        foreach (
            [
                'transport' => ['controller' => 'Some\\Ctrl'],
                'metadata' => ['callback' => 'Some\\Callback'],
                'context' => ['route' => 'POST /admin'],
            ] as $container => $extra
        ) {
            $body = $this->validBody($signed, [$container => $extra]);
            try {
                $this->handlerFor($this->postRequest($body))
                    ->handle(new HugEventPayload(), new ResourceResponse());
                self::fail("Handler should have rejected nested {$container} smuggling");
            } catch (ValidationException $e) {
                $key = array_key_first($extra);
                self::assertArrayHasKey("{$container}.{$key}", $e->getErrorContext()['errors']);
            }
        }
    }

    #[Test]
    public function rejects_envelope_with_unsupported_schema_version(): void
    {
        $signed = SignedContext::sign(['x' => 1], 60);
        $body = $this->validBody($signed, ['schemaVersion' => 99]);

        $this->expectException(ValidationException::class);
        $this->handlerFor($this->postRequest($body))
            ->handle(new HugEventPayload(), new ResourceResponse());
    }

    #[Test]
    public function rejects_envelope_missing_event_id(): void
    {
        $signed = SignedContext::sign(['x' => 1], 60);
        $body = $this->validBody($signed, ['eventId' => '']);

        $this->expectException(ValidationException::class);
        $this->handlerFor($this->postRequest($body))
            ->handle(new HugEventPayload(), new ResourceResponse());
    }

    #[Test]
    public function rejects_non_object_body(): void
    {
        $req = new Request(
            method: 'POST',
            uri: '/__semitexa_hug',
            headers: ['content-type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: '"oops"',
        );

        $this->expectException(ValidationException::class);
        (new HugEventHandler())->withRequest($req)
            ->handle(new HugEventPayload(), new ResourceResponse());
    }

    #[Test]
    public function rejects_list_shaped_body(): void
    {
        // A bare JSON array passes the is_array() check but is NOT a JSON
        // object — without the array_is_list() guard it would fall through
        // into envelope validation and produce confusing per-field errors.
        $req = new Request(
            method: 'POST',
            uri: '/__semitexa_hug',
            headers: ['content-type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: '[{"handler":"X\\\\Y::z"}]',
        );

        try {
            (new HugEventHandler())->withRequest($req)
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('List-shaped JSON body must be rejected at the body guard.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body', $e->getErrorContext()['errors']);
        }
    }

    #[Test]
    public function rejects_handler_smuggling_nested_inside_list_array(): void
    {
        // Regression guard: list arrays inside a scanned container must be
        // walked so handler-identity fields cannot hide behind integer keys.
        // The expected dotted path uses the numeric key as a path segment.
        $signed = SignedContext::sign(['x' => 1], 60);
        $body = $this->validBody($signed, [
            'payload' => [
                'items' => [
                    ['handler' => 'X\\Y::z'],
                ],
            ],
        ]);

        try {
            $this->handlerFor($this->postRequest($body))
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('Envelope should have rejected payload.items.0.handler');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('payload.items.0.handler', $e->getErrorContext()['errors']);
        }
    }

    #[Test]
    public function a_retired_component_event_body_is_just_a_malformed_envelope(): void
    {
        // verify:accept-test-change the {componentEvent} path is gone (one component model: #[UiOn] on the canonical envelope); the body must now be refused, not routed
        try {
            $this->handlerFor($this->postRequest(['componentEvent' => ['component_id' => 'c1']]))
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('A {componentEvent} body must be refused.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('signedContext', $e->getErrorContext()['errors'], 'refused as an envelope missing its signed context');
        }
    }

    #[Test]
    public function a_feed_control_must_be_the_only_key(): void
    {
        try {
            $this->handlerFor($this->postRequest(['stream' => ['op' => 'subscribe'], 'eventId' => 'e1']))
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('A mixed body must be refused.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('stream', $e->getErrorContext()['errors']);
        }
    }

    #[Test]
    public function a_feed_control_answers_with_the_controls_status_and_body(): void
    {
        $routes = new \Semitexa\Core\Discovery\RouteRegistry();
        $control = (new \Semitexa\Ssr\Application\Service\Stream\FeedStreamControl())->withCollaborators(
            $routes,
            new \Semitexa\Core\Discovery\AttributeDiscovery(new \Semitexa\Core\Discovery\ClassDiscovery(), new \Semitexa\Core\ModuleRegistry(), new \Semitexa\Core\Discovery\RouteRegistry()),
            static fn (): object => new \stdClass(),
            new class implements \Semitexa\Ssr\Domain\Contract\FeedStreamSinkInterface {
                public function submitSubscribe(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot, string $routeName = '', ?string $requesterTenantId = null, bool $acceptsPatches = false): bool { return true; }
                public function submitViewChange(string $sessionId, array $params, ?string $streamingId = null): bool { return true; }
                public function submitUnsubscribe(string $sessionId, string $streamingId): bool { return true; }
            },
        );

        $response = $this->handlerFor($this->postRequest(['stream' => [
            'op' => 'subscribe', 'feed' => 'no.such.feed', 'params' => [],
            'session' => 'sse_' . str_repeat('a', 32), 'subscriptionId' => 'sse_' . str_repeat('b', 32),
        ]]))->withFeedStreams($control)->handle(new HugEventPayload(), new ResourceResponse());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('unknown_feed', json_decode($response->getContent(), true)['reason']);
    }

    // ---- tk-la-uploads: multipart {upload, file} ------------------------

    private function uploadRequest(array $post, array $files): Request
    {
        return new Request(
            method: 'POST',
            uri: '/__semitexa_hug',
            headers: ['content-type' => 'multipart/form-data; boundary=x'],
            query: [],
            post: $post,
            server: [],
            cookies: [],
            files: $files,
        );
    }

    /** @var list<string> temp files the upload tests made, removed in tearDown() */
    private array $tempFiles = [];

    private function uploadedFile(): \Semitexa\Core\Http\UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hug');
        file_put_contents($tmp, 'hello');
        $this->tempFiles[] = $tmp;

        return new \Semitexa\Core\Http\UploadedFile('file', 'a.txt', 'text/plain', 5, $tmp);
    }

    #[Test]
    public function an_upload_with_a_verified_upload_context_reaches_the_receiver(): void
    {
        $receiver = new HugTestUploadReceiver();
        $ctx = SignedContext::sign(['k' => 'upload', 'fn' => 'avatar', 'i' => 'uci_hug_upload_0001']);
        $response = $this->handlerFor($this->uploadRequest(['upload' => $ctx], ['file' => $this->uploadedFile()]))
            ->withUploads($receiver)
            ->handle(new HugEventPayload(), new ResourceResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('avatar', $receiver->claims['fn'] ?? null);
        self::assertSame(['status' => 'accepted', 'ticket' => 't'], json_decode($response->getContent(), true));
    }

    #[Test]
    public function an_event_context_cannot_be_used_to_upload(): void
    {
        $receiver = new HugTestUploadReceiver();
        $ctx = SignedContext::sign(['c' => 'demo', 'i' => 'uci_hug_upload_0001', 'p' => 'x', 'e' => 'click']);
        try {
            $this->handlerFor($this->uploadRequest(['upload' => $ctx], ['file' => $this->uploadedFile()]))
                ->withUploads($receiver)
                ->handle(new HugEventPayload(), new ResourceResponse());
            self::fail('expected a refusal');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('upload', $e->getErrors());
        }
        self::assertNull($receiver->claims, 'never reached the receiver');
    }

    #[Test]
    public function an_upload_body_is_exactly_upload_and_file(): void
    {
        $ctx = SignedContext::sign(['k' => 'upload', 'fn' => 'avatar']);
        $this->expectException(ValidationException::class);
        $this->handlerFor($this->uploadRequest(['upload' => $ctx, 'action' => 'admin.delete'], ['file' => $this->uploadedFile()]))
            ->withUploads(new HugTestUploadReceiver())
            ->handle(new HugEventPayload(), new ResourceResponse());
    }

    #[Test]
    public function without_a_bound_receiver_uploads_are_refused(): void
    {
        $ctx = SignedContext::sign(['k' => 'upload', 'fn' => 'avatar']);
        $response = $this->handlerFor($this->uploadRequest(['upload' => $ctx], ['file' => $this->uploadedFile()]))
            ->withUploads(new \Semitexa\Ssr\Application\Service\UiEvent\NotConfiguredHugUploadReceiver())
            ->handle(new HugEventPayload(), new ResourceResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('uploads_not_configured', json_decode($response->getContent(), true)['reason']);
    }
}

final class HugTestUploadReceiver implements \Semitexa\Ssr\Application\Service\UiEvent\HugUploadReceiverInterface
{
    /** @var array<string, mixed>|null */
    public ?array $claims = null;

    public function receive(array $claims, \Semitexa\Core\Http\UploadedFile $file): array
    {
        $this->claims = $claims;

        return [200, ['status' => 'accepted', 'ticket' => 't']];
    }
}
