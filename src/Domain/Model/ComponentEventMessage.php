<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Validation\Trait\NotBlankValidationTrait;

/**
 * One `#[AsComponent(event:, triggers:)]` event as `component-events.js`
 * sends it: `POST /__semitexa_hug` with `{"componentEvent": {…}}`. It used to
 * have its own route (`/__semitexa_component_event`); KISS and HUG are the
 * whole transport, so it rides HUG like every other UI event.
 */
final class ComponentEventMessage
{
    use NotBlankValidationTrait;

    /** Wire keys (snake_case, as the runtime sends them) to setters. */
    private const FIELDS = [
        'component_id' => 'setComponentId',
        'component_name' => 'setComponentName',
        'event_class' => 'setEventClass',
        'frontend_event' => 'setFrontendEvent',
        'signature' => 'setSignature',
        'page_path' => 'setPagePath',
        'session_binding' => 'setSessionBinding',
        'issued_at' => 'setIssuedAt',
        'declared_payload' => 'setDeclaredPayload',
        'interaction' => 'setInteraction',
    ];

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

    /**
     * @param array<string, mixed> $data
     * @throws ValidationException on a wrong type, an unknown key or a missing required field
     */
    public static function fromArray(array $data): self
    {
        $message = new self();
        $errors = [];
        foreach ($data as $key => $value) {
            $setter = self::FIELDS[$key] ?? null;
            if ($setter === null) {
                $errors[(string) $key] = ['Unknown field.'];
                continue;
            }
            $expectsArray = $key === 'declared_payload' || $key === 'interaction';
            $ok = match (true) {
                $expectsArray => is_array($value),
                $key === 'issued_at' => is_int($value),
                default => is_string($value),
            };
            if (!$ok) {
                $errors[$key] = ['Wrong type.'];
                continue;
            }
            // Every field is checked: a setter's refusal is collected, not thrown,
            // so one bad value does not hide the others.
            try {
                $message->{$setter}($value);
            } catch (ValidationException $e) {
                foreach ($e->getErrors() as $messages) {
                    $errors[$key] = array_values(array_merge($errors[$key] ?? [], (array) $messages));
                }
            }
        }
        // Missing required fields, reported under the wire key the client sends.
        $wireKeyOf = [];
        foreach (self::FIELDS as $wire => $setter) {
            $wireKeyOf[lcfirst(substr($setter, 3))] = $wire;
        }
        foreach ($message->validate() as $field => $messages) {
            $errors[$wireKeyOf[$field] ?? $field] ??= $messages;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $message;
    }

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];
        if ($this->componentId === '')   $errors['componentId'] = ['Field is required.'];
        if ($this->componentName === '') $errors['componentName'] = ['Field is required.'];
        if ($this->eventClass === '')    $errors['eventClass'] = ['Field is required.'];
        if ($this->frontendEvent === '') $errors['frontendEvent'] = ['Field is required.'];
        if ($this->signature === '')     $errors['signature'] = ['Field is required.'];
        if ($this->pagePath === '')      $errors['pagePath'] = ['Field is required.'];
        if ($this->issuedAt === 0)       $errors['issuedAt'] = ['Field is required.'];

        return $errors;
    }

    public function getComponentId(): string { return $this->componentId; }
    public function setComponentId(string $componentId): void
    {
        $this->componentId = $this->requireNotBlank('componentId', $componentId);
    }

    public function getComponentName(): string { return $this->componentName; }
    public function setComponentName(string $componentName): void
    {
        $this->componentName = $this->requireNotBlank('componentName', $componentName);
    }

    public function getEventClass(): string { return $this->eventClass; }
    public function setEventClass(string $eventClass): void
    {
        $this->eventClass = $this->requireNotBlank('eventClass', $eventClass);
    }

    public function getFrontendEvent(): string { return $this->frontendEvent; }
    public function setFrontendEvent(string $frontendEvent): void
    {
        $this->frontendEvent = $this->requireNotBlank('frontendEvent', $frontendEvent);
    }

    public function getSignature(): string { return $this->signature; }
    public function setSignature(string $signature): void
    {
        $this->signature = $this->requireNotBlank('signature', $signature);
    }

    public function getPagePath(): string { return $this->pagePath; }
    public function setPagePath(string $pagePath): void
    {
        $this->pagePath = $this->requireNotBlank('pagePath', $pagePath);
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
