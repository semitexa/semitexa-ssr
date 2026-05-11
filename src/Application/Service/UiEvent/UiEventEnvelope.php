<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * Canonical UI event envelope (framework-layer foundation).
 *
 * Sent by the frontend runtime to the unified HTTP UI event endpoint for any
 * primitive / part / component / composite source. The source kind is resolved
 * server-side from this envelope plus the opaque signed-context blob, never
 * from the URL.
 *
 * Step-1 scope: serialize / deserialize / shape-validate only. Handler
 * resolution, payload-DTO validation, replay/nonce/TTL enforcement, and
 * authorization are framework-layer concerns landing in later steps.
 *
 * Hard rule encoded here: this envelope MUST NOT carry handler identity,
 * payload-DTO FQCN, or authorization scope. Anything that could influence
 * server-side handler selection lives only inside the opaque $signedContext.
 */
final readonly class UiEventEnvelope
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $transport
     */
    public function __construct(
        public int $schemaVersion,
        public string $eventId,
        public string $correlationId,
        public string $semanticEvent,
        public string $signedContext,
        public string $timestamp,
        public array $payload = [],
        public array $transport = [],
        public ?string $nativeEvent = null,
        public ?string $primitiveName = null,
        public ?string $primitiveUi = null,
        public ?string $componentName = null,
        public ?string $componentInstanceId = null,
        public ?string $partName = null,
        public ?string $viewId = null,
        public ?string $renderId = null,
        public ?string $bindingPath = null,
        public mixed $value = null,
        public mixed $previousValue = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = self::validateShape($data);
        if ($errors !== []) {
            throw new InvalidUiEventEnvelopeException($errors);
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        /** @var array<string, mixed> $transport */
        $transport = is_array($data['transport'] ?? null) ? $data['transport'] : [];

        return new self(
            schemaVersion: (int) $data['schemaVersion'],
            eventId: (string) $data['eventId'],
            correlationId: (string) $data['correlationId'],
            semanticEvent: (string) $data['semanticEvent'],
            signedContext: (string) $data['signedContext'],
            timestamp: (string) $data['timestamp'],
            payload: $payload,
            transport: $transport,
            nativeEvent: self::optionalString($data, 'nativeEvent'),
            primitiveName: self::optionalString($data, 'primitiveName'),
            primitiveUi: self::optionalString($data, 'primitiveUi'),
            componentName: self::optionalString($data, 'componentName'),
            componentInstanceId: self::optionalString($data, 'componentInstanceId'),
            partName: self::optionalString($data, 'partName'),
            viewId: self::optionalString($data, 'viewId'),
            renderId: self::optionalString($data, 'renderId'),
            bindingPath: self::optionalString($data, 'bindingPath'),
            value: $data['value'] ?? null,
            previousValue: $data['previousValue'] ?? null,
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidUiEventEnvelopeException([
                'body' => ['Envelope JSON is malformed: ' . $e->getMessage()],
            ]);
        }

        if (!is_array($decoded)) {
            throw new InvalidUiEventEnvelopeException([
                'body' => ['Envelope must be a JSON object.'],
            ]);
        }

        return self::fromArray($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'eventId' => $this->eventId,
            'correlationId' => $this->correlationId,
            'semanticEvent' => $this->semanticEvent,
            'nativeEvent' => $this->nativeEvent,
            'primitiveName' => $this->primitiveName,
            'primitiveUi' => $this->primitiveUi,
            'componentName' => $this->componentName,
            'componentInstanceId' => $this->componentInstanceId,
            'partName' => $this->partName,
            'viewId' => $this->viewId,
            'renderId' => $this->renderId,
            'bindingPath' => $this->bindingPath,
            'value' => $this->value,
            'previousValue' => $this->previousValue,
            'payload' => $this->payload,
            'transport' => $this->transport,
            'signedContext' => $this->signedContext,
            'timestamp' => $this->timestamp,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Shape-only validation. Does NOT verify the signed context or any
     * authoritative server-side metadata — those are framework-layer concerns
     * landing in later steps.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, list<string>>
     */
    public static function validateShape(array $data): array
    {
        $errors = [];

        if (!isset($data['schemaVersion']) || !is_int($data['schemaVersion'])) {
            $errors['schemaVersion'] = ['Field is required and must be an integer.'];
        } elseif ($data['schemaVersion'] !== self::SCHEMA_VERSION) {
            $errors['schemaVersion'] = [sprintf('Unsupported schema version %d (expected %d).', $data['schemaVersion'], self::SCHEMA_VERSION)];
        }

        foreach (['eventId', 'correlationId', 'semanticEvent', 'signedContext', 'timestamp'] as $required) {
            $value = $data[$required] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[$required] = ['Field is required and must be a non-empty string.'];
            }
        }

        if (isset($data['payload']) && !is_array($data['payload'])) {
            $errors['payload'] = ['Field must be an object/map when provided.'];
        }

        if (isset($data['transport']) && !is_array($data['transport'])) {
            $errors['transport'] = ['Field must be an object/map when provided.'];
        }

        // Server-side metadata validated through signed context is the only
        // source of handler identity. The signed context may reference a
        // render id, manifest id, component instance id, or future
        // server-side metadata record. The backend resolves the actual
        // handler from server-side metadata. The frontend must never provide
        // handler identity — neither at the top level nor smuggled into a
        // nested object (payload / transport / metadata / context).
        self::collectHandlerSmugglingErrors($data, $errors, parentPath: null);

        return $errors;
    }

    /**
     * Field names that, if present in the envelope body — at the top level
     * OR nested inside a scanned container — are rejected.
     *
     * Includes the canonical names plus snake_case variants and obvious
     * adjacent attempts (controller / action / callback / endpoint / route /
     * url / method / backendHandler).
     *
     * @return list<string>
     */
    public static function handlerFieldsDisallowed(): array
    {
        return [
            // canonical handler identity
            'handler', 'handlerClass', 'handler_class',
            'handlerMethod', 'handler_method',
            'backendHandler', 'backend_handler',
            // generic backend-selection synonyms
            'method', 'controller', 'action', 'callback',
            'endpoint', 'route', 'url',
            // payload + authorization smuggling
            'payloadClass', 'payload_class',
            'authzScope', 'authz_scope',
        ];
    }

    /**
     * Containers inside the envelope that are scanned recursively for
     * forbidden handler-selection fields. Adding a key here extends the
     * smuggling check to that nested object.
     *
     * @return list<string>
     */
    public static function scannedContainers(): array
    {
        return ['payload', 'transport', 'metadata', 'context'];
    }

    /**
     * Walk the top-level data and each scanned container looking for forbidden
     * keys. Errors are keyed by the dotted path where the field was found
     * (e.g. "payload.handler") so the caller can fail loudly and pinpoint
     * exactly where the smuggling attempt landed.
     *
     * @param array<array-key, mixed>           $data
     * @param array<string, list<string>>       $errors  passed by reference
     */
    private static function collectHandlerSmugglingErrors(array $data, array &$errors, ?string $parentPath): void
    {
        $disallowed = array_flip(self::handlerFieldsDisallowed());

        // top-level (or current level when recursed)
        foreach ($data as $key => $_value) {
            if (!is_string($key)) {
                continue;
            }
            if (isset($disallowed[$key])) {
                $path = $parentPath === null ? $key : ($parentPath . '.' . $key);
                $errors[$path] = [
                    'Field is not allowed — server-side metadata validated through signed context is the only source of handler identity.',
                ];
            }
        }

        // recurse into scanned containers (only at the top level — those
        // contents are then walked once for the disallow list, no deeper
        // recursion is needed to catch the targeted smuggling vectors).
        if ($parentPath === null) {
            foreach (self::scannedContainers() as $container) {
                $nested = $data[$container] ?? null;
                if (!is_array($nested)) {
                    continue;
                }
                self::collectHandlerSmugglingErrors($nested, $errors, parentPath: $container);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            return null;
        }
        $trimmed = trim($data[$key]);

        return $trimmed === '' ? null : $trimmed;
    }
}
