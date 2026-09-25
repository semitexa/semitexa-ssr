<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Ssr\Application\Service\Layout\PageBodyEndStore;
use Semitexa\Ssr\Domain\Contract\PageDocumentContributorInterface;

/**
 * Hands each request the registered page contributors, for the reason
 * {@see BindSiteHeadListener} hands it a head reader: documents are finalized
 * by static response code with no container. Binding the instances costs a
 * JSON request nothing — a contributor only runs when a full page is
 * finalized.
 */
// First in the phase, beside BindSiteHeadListener: an authorization refusal
// later in AuthCheck renders an error page, and that page gets the fragments too.
#[AsPipelineListener(phase: AuthCheck::class, priority: -1000)]
final class BindPageContributorsListener implements PipelineListenerInterface
{
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function handle(RequestPipelineContext $context): void
    {
        if (!$this->container instanceof SemitexaContainer) {
            PageBodyEndStore::reset();

            return;
        }

        PageBodyEndStore::bind($this->container->getAllImplementationsOf(PageDocumentContributorInterface::class));
    }
}
