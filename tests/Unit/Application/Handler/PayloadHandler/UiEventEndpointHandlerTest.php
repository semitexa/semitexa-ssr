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
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;

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

    private function handlerFor(Request $request): UiEventEndpointHandler
    {
        return (new UiEventEndpointHandler())->withRequest($request);
    }

    #[Test]
    public function accepts_valid_envelope_with_verified_signed_context(): void
    {
        $signed = SignedContext::sign(['ctx' => 'valid'], 60);
        $resource = $this->handlerFor($this->postRequest($this->validBody($signed)))
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());

        self::assertSame(202, $resource->getStatusCode());
        $body = json_decode($resource->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('accepted', $body['status']);
        self::assertSame('foundation', $body['phase']);
        self::assertSame('not_implemented', $body['resolution']['status']);
        self::assertTrue($body['signedContext']['verified']);
    }

    #[Test]
    public function reports_unverified_signed_context_when_tampered(): void
    {
        $signed = SignedContext::sign(['ctx' => 'valid'], 60) . 'TAMPER';
        $resource = $this->handlerFor($this->postRequest($this->validBody($signed)))
            ->handle(new UiEventEnvelopePayload(), new ResourceResponse());

        $body = json_decode($resource->getContent(), true);
        self::assertTrue($body['signedContext']['present']);
        self::assertFalse($body['signedContext']['verified']);
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
}
