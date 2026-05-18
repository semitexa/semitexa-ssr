<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Handler\PayloadHandler\UiEventEndpointHandler;
use Semitexa\Ssr\Application\Payload\Request\UiEventEnvelopePayload;
use Semitexa\Ssr\Application\Service\UiEvent\NotConfiguredUiResponseDispatcher;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatcherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatchResult;

final class UiEventEndpointHandlerTest extends TestCase
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
            uri: '/__ui/event',
            headers: ['content-type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function handlerFor(Request $request, ?UiResponseDispatcherInterface $dispatcher = null): UiEventEndpointHandler
    {
        // Default to the framework's not-configured dispatcher — that's
        // exactly what production wiring resolves when no platform
        // package overrides the contract, so it's the right baseline
        // for "no test seam injected" calls.
        $handler = (new UiEventEndpointHandler())->withRequest($request);
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
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());

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
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());

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
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());

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
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());

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
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());

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
                ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
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
                ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
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
                    ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
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
                ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
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
                ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
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
                    ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
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
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
    }

    #[Test]
    public function rejects_envelope_missing_event_id(): void
    {
        $signed = SignedContext::sign(['x' => 1], 60);
        $body = $this->validBody($signed, ['eventId' => '']);

        $this->expectException(ValidationException::class);
        $this->handlerFor($this->postRequest($body))
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
    }

    #[Test]
    public function rejects_non_object_body(): void
    {
        $req = new Request(
            method: 'POST',
            uri: '/__ui/event',
            headers: ['content-type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: '"oops"',
        );

        $this->expectException(ValidationException::class);
        (new UiEventEndpointHandler())->withRequest($req)
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
    }

    #[Test]
    public function rejects_list_shaped_body(): void
    {
        // A bare JSON array passes the is_array() check but is NOT a JSON
        // object — without the array_is_list() guard it would fall through
        // into envelope validation and produce confusing per-field errors.
        $req = new Request(
            method: 'POST',
            uri: '/__ui/event',
            headers: ['content-type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: '[{"handler":"X\\\\Y::z"}]',
        );

        try {
            (new UiEventEndpointHandler())->withRequest($req)
                ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
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
                ->handle(new UiEventEnvelopePayload(), new ResourceResponse());
            self::fail('Envelope should have rejected payload.items.0.handler');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('payload.items.0.handler', $e->getErrorContext()['errors']);
        }
    }
}
