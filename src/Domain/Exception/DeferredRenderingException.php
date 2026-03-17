<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Exception;

use Semitexa\Core\Http\HttpStatus;

class DeferredRenderingException extends \Semitexa\Core\Exception\DomainException
{
    public function __construct(string $message = 'Deferred rendering failed.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::InternalServerError;
    }
}
