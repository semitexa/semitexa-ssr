<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attribute\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Validation\Trait\NotBlankValidationTrait;

#[AsPayload(
    path: '/__semitexa_component_event',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
#[PublicEndpoint]
final class ComponentEventDispatchPayload
{
    use NotBlankValidationTrait;

    private string $componentId = '';
    private string $componentName = '';
    private string $eventClass = '';
    private string $frontendEvent = '';
    private string $signature = '';
    private string $pagePath = '';
    private string $sessionBinding = '';
    private int $issuedAt = 0;

    /** @var array<string, mixed> */
    private array $declaredPayload = [];

    /** @var array<string, mixed> */
    private array $interaction = [];

    public function getComponentId(): string { return $this->componentId; }
    public function setComponentId(string $componentId): void
    {
        $this->componentId = self::requireNotBlank('componentId', $componentId);
    }

    public function getComponentName(): string { return $this->componentName; }
    public function setComponentName(string $componentName): void
    {
        $this->componentName = self::requireNotBlank('componentName', $componentName);
    }

    public function getEventClass(): string { return $this->eventClass; }
    public function setEventClass(string $eventClass): void
    {
        $this->eventClass = self::requireNotBlank('eventClass', $eventClass);
    }

    public function getFrontendEvent(): string { return $this->frontendEvent; }
    public function setFrontendEvent(string $frontendEvent): void
    {
        $this->frontendEvent = self::requireNotBlank('frontendEvent', $frontendEvent);
    }

    public function getSignature(): string { return $this->signature; }
    public function setSignature(string $signature): void
    {
        $this->signature = self::requireNotBlank('signature', $signature);
    }

    public function getPagePath(): string { return $this->pagePath; }
    public function setPagePath(string $pagePath): void
    {
        $this->pagePath = self::requireNotBlank('pagePath', $pagePath);
    }

    public function getSessionBinding(): string { return $this->sessionBinding; }
    public function setSessionBinding(string $sessionBinding): void { $this->sessionBinding = trim($sessionBinding); }

    public function getIssuedAt(): int { return $this->issuedAt; }
    public function setIssuedAt(int $issuedAt): void
    {
        if ($issuedAt <= 0) {
            throw new ValidationException(['issuedAt' => ['Must be a positive UNIX timestamp.']]);
        }
        $this->issuedAt = $issuedAt;
    }

    /** @return array<string, mixed> */
    public function getDeclaredPayload(): array { return $this->declaredPayload; }
    /** @param array<string, mixed> $declaredPayload */
    public function setDeclaredPayload(array $declaredPayload): void { $this->declaredPayload = $declaredPayload; }

    /** @return array<string, mixed> */
    public function getInteraction(): array { return $this->interaction; }
    /** @param array<string, mixed> $interaction */
    public function setInteraction(array $interaction): void {
        $this->interaction = $interaction;
    }
}
