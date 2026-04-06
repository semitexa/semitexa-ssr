<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Payload\Request\DefaultNotFoundPagePayload;
use Semitexa\Ssr\Application\Resource\Response\DefaultErrorPageResource;

#[AsPayloadHandler(payload: DefaultNotFoundPagePayload::class, resource: DefaultErrorPageResource::class)]
final class DefaultNotFoundPageHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    public function handle(
        DefaultNotFoundPagePayload $payload,
        DefaultErrorPageResource $resource,
    ): DefaultErrorPageResource {
        return $resource
            ->pageTitle('404 Not Found')
            ->seoTagDefault('description', 'Semitexa could not match this browser request to an application route.')
            ->seoKeywords(['404 Not Found', 'Semitexa', 'not found', 'missing route'])
            ->withStatusCode(HttpStatus::NotFound->value)
            ->withReasonPhrase(HttpStatus::NotFound->reason())
            ->withEyebrow('Default SSR Error Page')
            ->withHeadline('The page you requested does not exist.')
            ->withSummary('Semitexa could not match this browser request to an application route.')
            ->withRequestDetails([
                'Method' => $this->request->getMethod(),
                'Path' => $this->request->getPath(),
                'Request ID' => $this->request->getHeader('X-Request-ID') ?: 'n/a',
            ])
            ->withDebugDetails(null);
    }
}
