<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Http\PayloadValidationResult;
use Semitexa\Core\Validation\Trait\NotBlankValidationTrait;

#[AsPayload(
    responseWith: ResourceResponse::class,
    path: '/__semitexa_component_event',
    methods: ['POST'],
    consumes: ['application/json'],
    produces: ['application/json'],
)]
#[PublicEndpoint]
final class ComponentEventDispatchPayload implements ValidatablePayload
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
    public function setComponentId(string $componentId): void { $this->componentId = trim($componentId); }

    public function getComponentName(): string { return $this->componentName; }
    public function setComponentName(string $componentName): void { $this->componentName = trim($componentName); }

    public function getEventClass(): string { return $this->eventClass; }
    public function setEventClass(string $eventClass): void { $this->eventClass = trim($eventClass); }

    public function getFrontendEvent(): string { return $this->frontendEvent; }
    public function setFrontendEvent(string $frontendEvent): void { $this->frontendEvent = trim($frontendEvent); }

    public function getSignature(): string { return $this->signature; }
    public function setSignature(string $signature): void { $this->signature = trim($signature); }

    public function getPagePath(): string { return $this->pagePath; }
    public function setPagePath(string $pagePath): void { $this->pagePath = trim($pagePath); }

    public function getSessionBinding(): string { return $this->sessionBinding; }
    public function setSessionBinding(string $sessionBinding): void { $this->sessionBinding = trim($sessionBinding); }

    public function getIssuedAt(): int { return $this->issuedAt; }
    public function setIssuedAt(int $issuedAt): void { $this->issuedAt = $issuedAt; }

    /** @return array<string, mixed> */
    public function getDeclaredPayload(): array { return $this->declaredPayload; }
    /** @param array<string, mixed> $declaredPayload */
    public function setDeclaredPayload(array $declaredPayload): void { $this->declaredPayload = $declaredPayload; }

    /** @return array<string, mixed> */
    public function getInteraction(): array { return $this->interaction; }
    /** @param array<string, mixed> $interaction */
    public function setInteraction(array $interaction): void { $this->interaction = $interaction; }

    public function validate(): PayloadValidationResult
    {
        $errors = [];

        $this->validateNotBlank('componentId', $this->componentId, $errors);
        $this->validateNotBlank('componentName', $this->componentName, $errors);
        $this->validateNotBlank('eventClass', $this->eventClass, $errors);
        $this->validateNotBlank('frontendEvent', $this->frontendEvent, $errors);
        $this->validateNotBlank('signature', $this->signature, $errors);
        $this->validateNotBlank('pagePath', $this->pagePath, $errors);

        if ($this->issuedAt <= 0) {
            $errors['issuedAt'][] = 'Must be a positive UNIX timestamp.';
        }

        return new PayloadValidationResult($errors === [], $errors);
    }
}
