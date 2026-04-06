<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Http\Response\HtmlResponse;

#[AsResource(
    handle: 'ssr_error_page',
    template: '@project-layouts-semitexa-ssr/pages/error-page.html.twig',
)]
final class DefaultErrorPageResource extends HtmlResponse implements ResourceInterface
{
    public function withStatusCode(int $statusCode): self
    {
        $this->setStatusCode($statusCode);

        return $this->with('statusCode', $statusCode);
    }

    public function withReasonPhrase(string $reasonPhrase): self
    {
        return $this->with('reasonPhrase', $reasonPhrase);
    }

    public function withEyebrow(string $eyebrow): self
    {
        return $this->with('eyebrow', $eyebrow);
    }

    public function withHeadline(string $headline): self
    {
        return $this->with('headline', $headline);
    }

    public function withSummary(string $summary): self
    {
        $this->seoTagDefault('description', $summary);

        return $this->with('summary', $summary);
    }

    /**
     * @param array<string, string> $requestDetails
     */
    public function withRequestDetails(array $requestDetails): self
    {
        return $this->with('requestDetails', $requestDetails);
    }

    /**
     * @param array<string, string>|null $debugDetails
     */
    public function withDebugDetails(?array $debugDetails): self
    {
        return $this->with('debugDetails', $debugDetails);
    }
}
