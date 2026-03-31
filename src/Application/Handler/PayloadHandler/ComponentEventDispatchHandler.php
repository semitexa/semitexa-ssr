<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Application\Payload\Request\ComponentEventDispatchPayload;
use Semitexa\Ssr\Component\ComponentEventBridge;
use Semitexa\Ssr\Component\ComponentRegistry;

#[AsPayloadHandler(payload: ComponentEventDispatchPayload::class, resource: GenericResponse::class)]
final class ComponentEventDispatchHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected EventDispatcherInterface $eventDispatcher;

    public function handle(ComponentEventDispatchPayload $payload, GenericResponse $resource): GenericResponse
    {
        if (!$this->isSameOriginRequest()) {
            throw new AccessDeniedException('Cross-origin component event dispatch is not allowed.');
        }

        $component = ComponentRegistry::get($payload->getComponentName());
        if ($component === null || ($component['event'] ?? null) === null) {
            throw new NotFoundException('Component event bridge metadata', $payload->getComponentName());
        }

        /** @var array{event: string, triggers: list<string>} $component */
        $frontendEvent = strtolower(trim($payload->getFrontendEvent()));
        if (!in_array($frontendEvent, $component['triggers'], true)) {
            throw new ValidationException([
                'frontendEvent' => ['Frontend trigger is not declared for this component.'],
            ]);
        }

        if ($component['event'] !== $payload->getEventClass()) {
            throw new ValidationException([
                'eventClass' => ['Posted event class does not match component contract.'],
            ]);
        }

        $manifest = [
            'componentId' => $payload->getComponentId(),
            'componentName' => $payload->getComponentName(),
            'eventClass' => $payload->getEventClass(),
            'triggers' => $component['triggers'],
            'endpoint' => ComponentEventBridge::ENDPOINT_PATH,
            'pagePath' => $payload->getPagePath(),
            'issuedAt' => $payload->getIssuedAt(),
            'sessionBinding' => $payload->getSessionBinding(),
            'signature' => $payload->getSignature(),
        ];

        if (!ComponentEventBridge::verifyManifest($manifest)) {
            throw new AccessDeniedException('Component event signature validation failed.');
        }

        if (!ComponentEventBridge::matchesCurrentSessionBinding($payload->getSessionBinding())) {
            throw new AccessDeniedException('Component event session validation failed.');
        }

        $eventPayload = array_merge(
            $payload->getDeclaredPayload(),
            $payload->getInteraction(),
            [
                '_frontend' => [
                    'event' => $frontendEvent,
                    'component_id' => $payload->getComponentId(),
                    'component_name' => $payload->getComponentName(),
                    'page' => $payload->getPagePath(),
                ],
            ],
        );

        $event = $this->eventDispatcher->create($payload->getEventClass(), $eventPayload);
        $this->eventDispatcher->dispatch($event);

        $body = json_encode([
            'status' => 'accepted',
            'component_id' => $payload->getComponentId(),
            'component_name' => $payload->getComponentName(),
            'frontend_event' => $frontendEvent,
            'event_class' => $payload->getEventClass(),
            'page_path' => $payload->getPagePath(),
            'accepted_at' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent($body);
    }

    private function isSameOriginRequest(): bool
    {
        $ctx = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($ctx === null) {
            return true;
        }

        $request = $ctx[0];
        /** @var array<string, mixed> $headers */
        $headers = is_array($request->header ?? null) ? $request->header : [];
        $host = $this->readHeader($headers, 'host');
        if ($host === '') {
            return false;
        }

        $expectedOrigin = $this->parseAuthority($host, false);
        if ($expectedOrigin === null) {
            return false;
        }

        $checkedAnyOriginHeader = false;

        foreach (['origin', 'referer'] as $headerName) {
            $value = $this->readHeader($headers, $headerName);
            if ($value === '') {
                continue;
            }

            $checkedAnyOriginHeader = true;

            $requestOrigin = $this->parseAuthority($value, true);
            if ($requestOrigin === null) {
                return false;
            }

            if (!$this->authoritiesMatch($expectedOrigin, $requestOrigin)) {
                return false;
            }
        }

        return $checkedAnyOriginHeader;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function readHeader(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array{host: string, port: ?int, scheme: ?string}|null
     */
    private function parseAuthority(string $value, bool $isUrl): ?array
    {
        $input = trim($value);
        if ($input === '') {
            return null;
        }

        if (!$isUrl) {
            $input = 'http://' . $input;
        }

        $host = parse_url($input, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $scheme = parse_url($input, PHP_URL_SCHEME);
        $port = parse_url($input, PHP_URL_PORT);

        return [
            'host' => strtolower($host),
            'port' => is_int($port) ? $port : null,
            'scheme' => is_string($scheme) && $scheme !== '' ? strtolower($scheme) : null,
        ];
    }

    /**
     * @param array{host: string, port: ?int, scheme: ?string} $expected
     * @param array{host: string, port: ?int, scheme: ?string} $actual
     */
    private function authoritiesMatch(array $expected, array $actual): bool
    {
        if ($expected['host'] !== $actual['host']) {
            return false;
        }

        $expectedPort = $expected['port'] ?? $this->defaultPortForScheme($actual['scheme']);
        $actualPort = $actual['port'] ?? $this->defaultPortForScheme($actual['scheme']);

        return $expectedPort === $actualPort;
    }

    private function defaultPortForScheme(?string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
