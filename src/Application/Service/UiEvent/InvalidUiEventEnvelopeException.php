<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use RuntimeException;

/**
 * Raised when a UiEventEnvelope fails shape validation.
 *
 * Carries a Semitexa-style field-error map so handlers can map straight to a
 * 422-style response without inventing a new error envelope.
 */
final class InvalidUiEventEnvelopeException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(public readonly array $errors)
    {
        $first = '';
        foreach ($errors as $field => $messages) {
            $first = $field . ': ' . ($messages[0] ?? 'invalid');
            break;
        }
        parent::__construct($first === '' ? 'Invalid UI event envelope.' : 'Invalid UI event envelope — ' . $first);
    }
}
