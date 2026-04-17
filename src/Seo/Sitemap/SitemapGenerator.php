<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo\Sitemap;

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
    protected ?SitemapProviderRegistry $registry = null;

    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

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
        $urls = $this->collectUrls($context);
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

        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
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
     * @return list<SitemapUrl>
     */
    private function collectUrls(SitemapGenerationContext $context): array
    {
        if ($this->registry === null) {
            return [];
        }

        $urls = [];

        foreach ($this->registry->getProviders() as $providerMeta) {
            try {
                $provider = $this->resolveProvider($providerMeta['class']);
                if ($provider === null) {
                    continue;
                }

                $providerUrls = $provider->provideUrls($context);
                /** @var iterable<mixed> $providerUrls */
                foreach ($providerUrls as $url) {
                    if ($url instanceof SitemapUrl) {
                        $urls[] = $url;
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

        return $urls;
    }

    private function resolveProvider(string $className): ?SitemapUrlProviderInterface
    {
        try {
            if ($this->container !== null && $this->container->has($className)) {
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
        $tmpPath = $path . '.tmp.' . getmypid();

        if (file_put_contents($tmpPath, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write temporary sitemap file: %s', $tmpPath));
        }

        if (!rename($tmpPath, $path)) {
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
