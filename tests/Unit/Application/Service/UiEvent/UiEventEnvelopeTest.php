<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Service\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\UiEvent\InvalidUiEventEnvelopeException;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;

final class UiEventEnvelopeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validShape(array $overrides = []): array
    {
        return array_replace([
            'schemaVersion' => 1,
            'eventId' => 'evt_01',
            'correlationId' => 'corr_01',
            'semanticEvent' => 'click',
            'signedContext' => 'sc1.AAAA.BBBB',
            'timestamp' => '2026-05-11T10:00:00Z',
            'payload' => ['x' => 1],
            'transport' => ['kind' => 'http'],
            'primitiveName' => 'platform.button',
            'primitiveUi' => 'button',
        ], $overrides);
    }

    #[Test]
    public function round_trips_through_array_and_json(): void
    {
        $envelope = UiEventEnvelope::fromArray($this->validShape());

        $back = UiEventEnvelope::fromArray($envelope->toArray());
        self::assertSame($envelope->eventId, $back->eventId);
        self::assertSame($envelope->semanticEvent, $back->semanticEvent);
        self::assertSame($envelope->primitiveName, $back->primitiveName);
        self::assertSame($envelope->payload, $back->payload);

        $fromJson = UiEventEnvelope::fromJson($envelope->toJson());
        self::assertSame($envelope->eventId, $fromJson->eventId);
        self::assertSame($envelope->signedContext, $fromJson->signedContext);
    }

    #[Test]
    public function rejects_unsupported_schema_version(): void
    {
        try {
            UiEventEnvelope::fromArray($this->validShape(['schemaVersion' => 999]));
            self::fail('Expected InvalidUiEventEnvelopeException');
        } catch (InvalidUiEventEnvelopeException $e) {
            self::assertArrayHasKey('schemaVersion', $e->errors);
        }
    }

    #[Test]
    public function rejects_missing_required_fields(): void
    {
        try {
            UiEventEnvelope::fromArray([
                'schemaVersion' => 1,
                // missing eventId, correlationId, semanticEvent, signedContext, timestamp
            ]);
            self::fail('Expected InvalidUiEventEnvelopeException');
        } catch (InvalidUiEventEnvelopeException $e) {
            foreach (['eventId', 'correlationId', 'semanticEvent', 'signedContext', 'timestamp'] as $field) {
                self::assertArrayHasKey($field, $e->errors, "expected error on '{$field}'");
            }
        }
    }

    #[Test]
    public function rejects_handler_selection_fields_from_frontend(): void
    {
        // Sanity-check the canonical set published by the envelope so future
        // edits cannot silently drop a name. Snake_case + adjacent synonyms
        // (method/controller/action/callback/endpoint/route/url) are all
        // covered.
        $expected = [
            'handler', 'handlerClass', 'handler_class',
            'handlerMethod', 'handler_method',
            'backendHandler', 'backend_handler',
            'method', 'controller', 'action', 'callback',
            'endpoint', 'route', 'url',
            'payloadClass', 'payload_class',
            'authzScope', 'authz_scope',
        ];
        self::assertSame($expected, UiEventEnvelope::handlerFieldsDisallowed());

        foreach ($expected as $field) {
            try {
                UiEventEnvelope::fromArray($this->validShape([$field => 'Some\\Handler::method']));
                self::fail("Envelope should have rejected top-level '{$field}'");
            } catch (InvalidUiEventEnvelopeException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }
    }

    #[Test]
    public function rejects_handler_selection_fields_nested_inside_payload(): void
    {
        try {
            UiEventEnvelope::fromArray($this->validShape([
                'payload' => ['nested' => 'ok', 'handler' => 'X\\Y::z'],
            ]));
            self::fail('Envelope should have rejected payload.handler');
        } catch (InvalidUiEventEnvelopeException $e) {
            self::assertArrayHasKey('payload.handler', $e->errors);
        }
    }

    #[Test]
    public function rejects_handler_selection_fields_nested_deep_inside_payload(): void
    {
        try {
            UiEventEnvelope::fromArray($this->validShape([
                'payload' => [
                    'meta' => [
                        'dispatch' => [
                            'handler' => 'X\\Y::z',
                        ],
                    ],
                ],
            ]));
            self::fail('Envelope should have rejected payload.meta.dispatch.handler');
        } catch (InvalidUiEventEnvelopeException $e) {
            self::assertArrayHasKey('payload.meta.dispatch.handler', $e->errors);
        }
    }

    #[Test]
    public function rejects_handler_selection_fields_nested_inside_list_arrays(): void
    {
        // Regression: list arrays inside scanned containers must be walked
        // so handler-identity fields cannot hide behind integer keys.
        // Numeric keys appear as path segments in the dotted error key.
        try {
            UiEventEnvelope::fromArray($this->validShape([
                'payload' => ['items' => [['handler' => 'X\\Y::z']]],
            ]));
            self::fail('Envelope should have rejected payload.items.0.handler');
        } catch (InvalidUiEventEnvelopeException $e) {
            self::assertArrayHasKey('payload.items.0.handler', $e->errors);
        }
    }

    #[Test]
    public function rejects_handler_selection_fields_nested_inside_transport_metadata_context(): void
    {
        foreach (['transport', 'metadata', 'context'] as $container) {
            try {
                UiEventEnvelope::fromArray($this->validShape([
                    $container => ['controller' => 'X\\Controller'],
                ]));
                self::fail("Envelope should have rejected {$container}.controller");
            } catch (InvalidUiEventEnvelopeException $e) {
                self::assertArrayHasKey($container . '.controller', $e->errors);
            }
        }
    }

    #[Test]
    public function scanned_containers_list_is_stable(): void
    {
        // The list of containers that are recursively scanned for smuggling
        // is part of the public security contract — pin it so additions are
        // explicit.
        self::assertSame(['payload', 'transport', 'metadata', 'context'], UiEventEnvelope::scannedContainers());
    }

    #[Test]
    public function rejects_malformed_json(): void
    {
        $this->expectException(InvalidUiEventEnvelopeException::class);
        UiEventEnvelope::fromJson('{not json');
    }

    #[Test]
    public function rejects_non_object_payload_and_transport(): void
    {
        try {
            UiEventEnvelope::fromArray($this->validShape(['payload' => 'oops']));
            self::fail();
        } catch (InvalidUiEventEnvelopeException $e) {
            self::assertArrayHasKey('payload', $e->errors);
        }

        try {
            UiEventEnvelope::fromArray($this->validShape(['transport' => 'oops']));
            self::fail();
        } catch (InvalidUiEventEnvelopeException $e) {
            self::assertArrayHasKey('transport', $e->errors);
        }
    }
}
