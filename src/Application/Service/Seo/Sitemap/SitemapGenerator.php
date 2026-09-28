<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Orchestrates sitemap generation by collecting URLs from all registered
 * providers and rendering them as XML.
 *
 * Supports automatic splitting into a sitemap index when the URL count
 * exceeds the sitemap protocol limit (50,000 URLs per file).
 */
#[AsService]
final class SitemapGenerator
{
    private const int MAX_URLS_PER_SITEMAP = 50_000;
    private const string SITEMAP_XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const string XHTML_XMLNS = 'http://www.w3.org/1999/xhtml';

    #[InjectAsReadonly]
    protected SitemapProviderRegistry $registry;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /**
     * Generate sitemap XML content.
     *
     * Returns either a single sitemap XML or a sitemap index XML
     * when the total URL count exceeds the per-file limit.
     *
     * @return array{xml: string, parts: array<string, string>, totalUrls: int}
     *   - xml: The primary sitemap.xml content (standalone or index)
     *   - parts: Map of filename => XML content for split sitemaps (empty if no split needed)
     *   - totalUrls: Total number of URLs collected
     */
    public function generate(SitemapGenerationContext $context): array
    {
        $urls = $this->urls($context);
        $totalUrls = count($urls);

        if ($totalUrls <= self::MAX_URLS_PER_SITEMAP) {
            return [
                'xml' => $this->renderSitemapXml($urls),
                'parts' => [],
                'totalUrls' => $totalUrls,
            ];
        }

        return $this->renderSitemapIndex($urls, $context);
    }

    /**
     * Generate sitemap and write to disk with atomic rename.
     *
     * If generation fails, the previous sitemap files remain untouched.
     */
    public function generateAndWrite(SitemapGenerationContext $context, string $outputDir): SitemapWriteResult
    {
        try {
            $result = $this->generate($context);
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('ssr', 'Sitemap generation failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return new SitemapWriteResult(
                success: false,
                totalUrls: 0,
                filesWritten: 0,
                primaryPath: $outputDir . '/sitemap.xml',
            );
        }

        return $this->write($result, $outputDir);
    }

    /**
     * Persist an already generated result: parts first, the index last, each
     * through a temporary file and a rename, so a concurrent reader sees either
     * the old file or the new one — never a partial one. Never throws: a
     * failure is logged and reported in the result, so a request that
     * regenerated the sitemap can still serve what it generated.
     *
     * @param array{xml: string, parts: array<string, string>, totalUrls: int} $result
     */
    public function write(array $result, string $outputDir): SitemapWriteResult
    {
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            StaticLoggerBridge::error('ssr', 'Sitemap output directory could not be created', [
                'path' => $outputDir,
            ]);

            return new SitemapWriteResult(
                success: false,
                totalUrls: $result['totalUrls'],
                filesWritten: 0,
                primaryPath: $outputDir . '/sitemap.xml',
            );
        }

        $filesWritten = 0;

        try {
            // Write part files first (if sitemap index)
            foreach ($result['parts'] as $filename => $xml) {
                $this->atomicWrite($outputDir . '/' . $filename, $xml);
                $filesWritten++;
            }

            // Write primary sitemap.xml last
            $this->atomicWrite($outputDir . '/sitemap.xml', $result['xml']);
            $filesWritten++;

            // Then the stamp of the code that wrote it. Last on purpose: a
            // crash before it leaves a new index with an old stamp, which reads
            // as stale and regenerates — never an old index passed as current.
            $identity = GeneratedSitemapCache::codeIdentity();
            if ($identity !== null) {
                $this->atomicWrite($outputDir . '/' . GeneratedSitemapCache::CODE_STAMP, $identity);
            }
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('ssr', 'Sitemap write failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'outputDir' => $outputDir,
            ]);

            return new SitemapWriteResult(
                success: false,
                totalUrls: $result['totalUrls'],
                filesWritten: $filesWritten,
                primaryPath: $outputDir . '/sitemap.xml',
            );
        }

        // Clean up stale part files from previous generations
        $this->cleanStaleParts($outputDir, $result['parts']);

        return new SitemapWriteResult(
            success: true,
            totalUrls: $result['totalUrls'],
            filesWritten: $filesWritten,
            primaryPath: $outputDir . '/sitemap.xml',
        );
    }

    /**
     * Every URL the tenant's providers vouch for, one entry per `loc`.
     *
     * Providers run in priority order — custom module providers before the
     * route-based default (priority 1000) — and the FIRST provider to name a
     * `loc` wins. Without this, two modules that both declare `/` put the home
     * page into sitemap.xml twice, and a custom provider that knows an
     * article's title and lastmod was shadowed by the default's bare copy.
     *
     * Public so the machine-readable summaries (/sitemap.json, llms.txt) can
     * list the same pages the sitemap does, with titles when providers give them.
     *
     * @return list<SitemapUrl>
     */
    public function urls(SitemapGenerationContext $context): array
    {
        return $this->collectUrls($context);
    }

    /**
     * @return list<SitemapUrl>
     */
    private function collectUrls(SitemapGenerationContext $context): array
    {
        if (!isset($this->registry)) {
            return [];
        }

        /** @var array<string, SitemapUrl> $urls */
        $urls = [];

        foreach ($this->registry->getProvidersForTenant($context->tenantContext) as $providerMeta) {
            try {
                $provider = $this->resolveProvider($providerMeta['class']);
                if ($provider === null) {
                    continue;
                }

                $providerUrls = $provider->provideUrls($context);
                /** @var iterable<mixed> $providerUrls */
                foreach ($providerUrls as $url) {
                    if ($url instanceof SitemapUrl && !isset($urls[$url->loc])) {
                        $urls[$url->loc] = $url;
                    }
                }
            } catch (\Throwable $e) {
                StaticLoggerBridge::warning('ssr', 'Sitemap provider failed, skipping', [
                    'class' => $providerMeta['class'],
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return array_values($urls);
    }

    private function resolveProvider(string $className): ?SitemapUrlProviderInterface
    {
        try {
            if (isset($this->container) && $this->container->has($className)) {
                $instance = $this->container->get($className);
            } else {
                $instance = new $className();
            }
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('ssr', 'Failed to instantiate sitemap provider', [
                'class' => $className,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$instance instanceof SitemapUrlProviderInterface) {
            return null;
        }

        return $instance;
    }

    /**
     * @param list<SitemapUrl> $urls
     */
    private function renderSitemapXml(array $urls): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', self::SITEMAP_XMLNS);
        $writer->writeAttribute('xmlns:xhtml', self::XHTML_XMLNS);

        foreach ($urls as $url) {
            $writer->startElement('url');
            $writer->writeElement('loc', $url->loc);

            if ($url->lastmod !== null) {
                $writer->writeElement('lastmod', $url->lastmod->format('Y-m-d'));
            }

            if ($url->changefreq !== null) {
                $writer->writeElement('changefreq', $url->changefreq);
            }

            if ($url->priority !== null) {
                $writer->writeElement('priority', number_format($url->priority, 1));
            }

            foreach ($url->alternates as $alternate) {
                $writer->startElement('xhtml:link');
                $writer->writeAttribute('rel', $alternate->rel);
                $writer->writeAttribute('href', $alternate->href);

                if ($alternate->hreflang !== null) {
                    $writer->writeAttribute('hreflang', $alternate->hreflang);
                }

                if ($alternate->type !== null) {
                    $writer->writeAttribute('type', $alternate->type);
                }

                $writer->endElement();
            }

            $writer->endElement(); // url
        }

        $writer->endElement(); // urlset
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * @param list<SitemapUrl> $urls
     * @return array{xml: string, parts: array<string, string>, totalUrls: int}
     */
    private function renderSitemapIndex(array $urls, SitemapGenerationContext $context): array
    {
        $chunks = array_chunk($urls, self::MAX_URLS_PER_SITEMAP);
        $parts = [];
        $totalUrls = count($urls);

        foreach ($chunks as $index => $chunk) {
            $partNumber = $index + 1;
            $filename = sprintf('sitemap-%d.xml', $partNumber);
            $parts[$filename] = $this->renderSitemapXml($chunk);
        }

        // Build sitemap index
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', self::SITEMAP_XMLNS);

        foreach (array_keys($parts) as $filename) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', rtrim($context->baseUrl, '/') . '/' . $filename);
            $writer->writeElement('lastmod', gmdate('Y-m-d'));
            $writer->endElement();
        }

        $writer->endElement(); // sitemapindex
        $writer->endDocument();

        return [
            'xml' => $writer->outputMemory(),
            'parts' => $parts,
            'totalUrls' => $totalUrls,
        ];
    }

    private function atomicWrite(string $path, string $content): void
    {
        // Unique per write: getmypid() alone is shared by every coroutine of a worker.
        $tmpPath = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmpPath, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write temporary sitemap file: %s', $tmpPath));
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException(sprintf('Failed to rename temporary sitemap file: %s → %s', $tmpPath, $path));
        }
    }

    /**
     * Remove sitemap part files from previous generations that are no longer needed.
     *
     * @param array<string, string> $currentParts
     */
    private function cleanStaleParts(string $outputDir, array $currentParts): void
    {
        $pattern = $outputDir . '/sitemap-*.xml';
        foreach (glob($pattern) ?: [] as $file) {
            $basename = basename($file);
            if (!isset($currentParts[$basename])) {
                @unlink($file);
            }
        }
    }
}
