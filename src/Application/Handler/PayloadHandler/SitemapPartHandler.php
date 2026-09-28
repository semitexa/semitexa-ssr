<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Payload\Request\SitemapPartPayload;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapLocator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\GeneratedSitemapCache;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapStoragePath;

#[AsPayloadHandler(payload: SitemapPartPayload::class, resource: ResourceResponse::class)]
final class SitemapPartHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected TenantContextInterface $tenantContext;

    #[InjectAsReadonly]
    protected SitemapGenerator $generator;

    public function handle(SitemapPartPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $part = preg_replace('/[^a-zA-Z0-9_-]/', '', $payload->part);
        if ($part === '' || $part === null) {
            return $resource
                ->setContent('')
                ->setStatusCode(404);
        }

        $filename = sprintf('sitemap-%s.xml', $part);
        $content = $this->resolvePart($filename);

        if ($content !== null) {
            return $resource
                ->setContent($content)
                ->setHeader('Content-Type', 'application/xml; charset=utf-8');
        }

        return $resource
            ->setContent('')
            ->setStatusCode(404);
    }

    /**
     * Same order as sitemap.xml: a generated part, then the project's manual
     * files, then — when the generated set is stale or missing — a regeneration
     * of the whole set, so a part never outlives its index.
     *
     * A part's freshness is its index's: parts are written just before the
     * index, so near the TTL boundary a part can be a moment older than the
     * index that links to it, and judging it by its own age would 404 a part
     * the live index advertises.
     */
    private function resolvePart(string $filename): ?string
    {
        $generatedDir = SitemapStoragePath::generatedDirectory($this->tenantContext);
        $indexIsFresh = GeneratedSitemapCache::readFreshIndex($generatedDir) !== null;

        if ($indexIsFresh) {
            $content = GeneratedSitemapCache::readFresh($generatedDir . '/' . $filename, PHP_INT_MAX);
            if ($content !== null) {
                return $content;
            }
        }

        $projectRoot = ProjectRoot::get();
        foreach ([$projectRoot . '/' . $filename, $projectRoot . '/public/' . $filename] as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $manual = file_get_contents($candidate);
            if ($manual !== false) {
                return $manual;
            }
        }

        // A fresh index means the set is current and this part simply does not
        // exist; regenerating on every unknown part name would hand anyone a
        // way to run the generator at will.
        // Only a name the generator can produce may start a regeneration:
        // with an unwritable directory the index never turns fresh, and any
        // other name would run the generator on every request.
        if (!isset($this->generator) || $indexIsFresh || preg_match('/^sitemap-\d+\.xml$/', $filename) !== 1) {
            return null;
        }

        $generated = $this->generator->generate(new SitemapGenerationContext(
            baseUrl: AiSitemapLocator::originUrl($this->request, $this->tenantContext),
            tenantContext: $this->tenantContext,
        ));
        // Served from what was just generated, not re-read from disk: when the
        // directory cannot be written, sitemap.xml still serves an index built
        // in memory, and its parts must answer the same way rather than 404.
        $this->generator->write($generated, $generatedDir);

        return $generated['parts'][$filename] ?? null;
    }
}
