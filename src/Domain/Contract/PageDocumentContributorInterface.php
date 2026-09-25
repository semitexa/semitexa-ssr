<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Lets a package add markup to the end of every full page this request
 * renders — a dev toolbar, a consent banner, an analytics snippet — without
 * the page's templates knowing it exists.
 *
 * Register with `#[AsService]` + `#[SatisfiesServiceContract(of:
 * PageDocumentContributorInterface::class)]`; every registered contributor
 * runs. {@see \Semitexa\Ssr\Application\Service\Layout\PageBodyEndStore}
 * places the fragments just before `</body>`, once per document, in the
 * request's own coroutine, so a contributor may read request-scoped state.
 *
 * Contributors are container singletons shared by every request of the
 * worker: keep them stateless. A contributor that throws costs the page its
 * fragment, not the page.
 */
interface PageDocumentContributorInterface
{
    /**
     * HTML to place just before `</body>`, or '' to add nothing to this page.
     * Must not carry an inline script without the request's CSP nonce.
     */
    public function bodyEnd(): string;
}
