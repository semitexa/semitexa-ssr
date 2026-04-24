<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Http\Response\FallbackErrorPage;
use Semitexa\Ssr\Http\Response\HtmlResponse;

/**
 * Default SSR error page (404 + 500).
 *
 * Template path `@project-layouts-theme-base/pages/error-page.html.twig` is a
 * stable framework contract — `theme-base` is the canonical module name
 * published by `semitexa/theme`. Project-local overrides via
 * `src/theme/<active-theme>/theme-base/templates/pages/error-page.html.twig`
 * are respected by `ThemeAwareTwigLoader` without touching this class.
 *
 * If the theme layer itself is broken (missing package, resolver error,
 * template not found, Twig crash), `renderTemplate()` catches the failure
 * and emits {@see FallbackErrorPage} — a pure-PHP nuclear fallback.
 */
#[AsResource(
    handle: 'ssr_error_page',
    template: '@project-layouts-theme-base/pages/error-page.html.twig',
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

    /**
     * Renders the error template; falls back to pure-PHP {@see FallbackErrorPage}
     * when the theme layer cannot produce the page.
     *
     * @param array<string, mixed> $extraContext
     */
    public function renderTemplate(?string $template = null, array $extraContext = []): static
    {
        try {
            return parent::renderTemplate($template, $extraContext);
        } catch (\Throwable $e) {
            $status = $this->getStatusCode() ?: 500;
            $context = $this->getRenderContext();
            $reason = is_string($context['reasonPhrase'] ?? null) ? $context['reasonPhrase'] : 'Internal Server Error';
            $this->setContent(FallbackErrorPage::render($status, $reason, $e->getMessage()));

            return $this;
        }
    }
}
