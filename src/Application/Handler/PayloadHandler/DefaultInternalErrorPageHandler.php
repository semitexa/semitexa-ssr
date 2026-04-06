<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Error\ErrorPageContext;
use Semitexa\Core\Error\ErrorPageContextStore;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Payload\Request\DefaultInternalErrorPagePayload;
use Semitexa\Ssr\Application\Resource\Response\DefaultErrorPageResource;

#[AsPayloadHandler(payload: DefaultInternalErrorPagePayload::class, resource: DefaultErrorPageResource::class)]
final class DefaultInternalErrorPageHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    public function handle(
        DefaultInternalErrorPagePayload $payload,
        DefaultErrorPageResource $resource,
    ): DefaultErrorPageResource {
        $context = $this->resolveContext();

        return $resource
            ->pageTitle('500 Internal Server Error')
            ->seoTagDefault('description', 'The request reached Semitexa, but rendering failed before a page could complete.')
            ->seoKeywords(['500 Internal Server Error', 'Semitexa', 'server error', 'rendering failed'])
            ->withStatusCode(HttpStatus::InternalServerError->value)
            ->withReasonPhrase(HttpStatus::InternalServerError->reason())
            ->withEyebrow('Default SSR Error Page')
            ->withHeadline('The request reached Semitexa, but rendering failed before a page could complete.')
            ->withSummary('This is the framework fallback SSR page. Register your own `error.500` route to replace it project-wide.')
            ->withRequestDetails([
                'Method' => $this->request->getMethod(),
                'Path' => $this->request->getPath(),
                'Request ID' => $this->request->getHeader('X-Request-ID') ?: 'n/a',
            ])
            ->withDebugDetails($context?->debugEnabled === true ? [
                'Exception' => $context->exceptionClass ?? 'n/a',
                'Message' => $context->debugMessage ?? 'n/a',
                'Origin route' => $context->originalRouteName ?? 'n/a',
                'Trace' => $context->trace ?? 'n/a',
            ] : null);
    }

    private function resolveContext(): ?ErrorPageContext
    {
        $context = ErrorPageContextStore::current();

        return $context instanceof ErrorPageContext ? $context : null;
    }
}
