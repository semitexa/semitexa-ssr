<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadReader;
use Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadStore;
use Semitexa\Ssr\Domain\Model\SiteHead;

/**
 * Hands each request a reader for the site's head values.
 *
 * A pipeline listener for the same reason as
 * {@see BindRequestToComponentRendererListener}: the value is per request and
 * per tenant, and the documents it lands in are finalized by static response
 * code with no container. Binding a reader rather than reading here keeps
 * requests that never render a page off the settings table.
 */
// First in the phase (ascending order): an authorization or CSRF refusal later
// in AuthCheck renders an error page, and that page is the site's too.
#[AsPipelineListener(phase: AuthCheck::class, priority: -1000)]
final class BindSiteHeadListener implements PipelineListenerInterface
{
    #[InjectAsReadonly]
    protected SiteHeadReader $reader;

    public function handle(RequestPipelineContext $context): void
    {
        $reader = $this->reader;
        SiteHeadStore::bind(static fn (): SiteHead => $reader->current());
    }
}
