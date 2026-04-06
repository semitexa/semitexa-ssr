<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Http\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\HttpResponse as CoreResponse;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Context\IsomorphicContextStore;
use Semitexa\Ssr\Context\PageRenderContextStore;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Seo\SeoMeta;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

class HtmlResponse extends ResourceResponse
{
    private ?string $declaredTemplate = null;
    private static array $attributeCache = [];
    private bool $autoRenderEnabled = true;

    public function __construct()
    {
        parent::__construct('', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        SeoMeta::reset();
        $this->initFromAttribute();
    }

    /**
     * Sets the browser tab / SEO title via SeoMeta.
     */
    public function pageTitle(string $title, ?string $suffix = null, ?string $prefix = null): static
    {
        SeoMeta::setTitle($title, $suffix, $prefix);
        SeoMeta::setDefault('og:title', $title);
        return $this;
    }

    /**
     * Sets an arbitrary SEO meta tag via SeoMeta.
     */
    public function seoTag(string $name, string $content): static
    {
        SeoMeta::tag($name, $content);
        return $this;
    }

    public function seoTagDefault(string $name, string $content): static
    {
        SeoMeta::setDefault($name, $content);
        return $this;
    }

    /**
     * @param array<int, string|array{term?: string, title?: string, label?: string, name?: string}> $keywords
     */
    public function seoKeywords(array $keywords): static
    {
        $existing = SeoMeta::get('keywords');
        $items = $existing !== null
            ? array_values(array_filter(array_map('trim', explode(',', $existing)), static fn (string $item): bool => $item !== ''))
            : [];

        foreach ($keywords as $keyword) {
            $value = null;

            if (is_string($keyword)) {
                $value = trim($keyword);
            } elseif (is_array($keyword)) {
                foreach (['term', 'title', 'label', 'name'] as $key) {
                    if (isset($keyword[$key]) && is_string($keyword[$key])) {
                        $value = trim($keyword[$key]);
                        break;
                    }
                }
            }

            if ($value !== null && $value !== '') {
                $items[] = $value;
            }
        }

        $items = array_values(array_unique($items));
        if ($items === []) {
            return $this;
        }

        SeoMeta::tag('keywords', implode(', ', $items));
        return $this;
    }

    /**
     * Adds a single typed variable to the render context.
     * Intended for use by typed with*() methods in Resource subclasses.
     */
    protected function with(string $key, mixed $value): static
    {
        $context = $this->getRenderContext();
        $context[$key] = $value;
        $this->setRenderContext($context);
        return $this;
    }

    /**
     * Renders a Twig template and stores the resulting HTML as response content.
     *
     * When $template is omitted, falls back to the template declared via #[AsResource].
     * When a render handle is set, injects page_handle and layout_handle into context.
     * The $extraContext array is merged on top of the accumulated render context.
     */
    public function renderTemplate(?string $template = null, array $extraContext = []): static
    {
        $tmpl = $template ?? $this->declaredTemplate;
        if ($tmpl === null) {
            throw new \LogicException(
                'No template specified and no #[AsResource] template declared on ' . static::class
            );
        }

        $this->beginTopLevelRender();

        // Populate AssetCollector before Twig renders so {{ asset_head() }} has data
        $this->prepareAssetCollector($tmpl);

        $context = $this->getRenderContext();
        if ($extraContext !== []) {
            $context = array_merge($context, $extraContext);
        }

        $handle = $this->getRenderHandle();
        if ($handle !== null) {
            $context['page_handle'] ??= $handle;
            $context['layout_handle'] ??= $handle;
        }

        $context['response'] ??= $this;

        $context = $this->applyIsomorphicContext($context);
        self::applySeoDefaults($context);
        PageRenderContextStore::set($context);

        try {
            $html = ModuleTemplateRegistry::getTwig()->render($tmpl, $context);
            $this->setContent($html);
        } finally {
            PageRenderContextStore::reset();
        }

        return $this;
    }

    public function renderString(string $templateSource, array $context = []): static
    {
        $this->beginTopLevelRender();

        $context = $this->applyIsomorphicContext($context);
        self::applySeoDefaults($context);

        $twig = ModuleTemplateRegistry::getTwig();
        $template = $twig->createTemplate($templateSource);
        $html = $template->render($context);
        $this->setContent($html);
        return $this;
    }

    public function getDeclaredTemplate(): ?string
    {
        return $this->declaredTemplate;
    }

    public function setDeclaredTemplate(?string $template): static
    {
        $this->declaredTemplate = $template;
        return $this;
    }

    public function disableAutoRender(): static
    {
        $this->autoRenderEnabled = false;
        return $this;
    }

    public function enableAutoRender(): static
    {
        $this->autoRenderEnabled = true;
        return $this;
    }

    /**
     * Auto-renders the declared template if no content has been set by the handler pipeline.
     *
     * renderTemplate() internally calls prepareAssetCollector() so that
     * {{ asset_head() }} and {{ asset_body() }} emit the correct tags.
     */
    public function toCoreResponse(): CoreResponse
    {
        if (
            $this->autoRenderEnabled
            && $this->getContent() === ''
            && $this->declaredTemplate !== null
            && !in_array($this->getStatusCode(), [204, 304], true)
        ) {
            $this->renderTemplate($this->declaredTemplate);
        }
        return parent::toCoreResponse();
    }

    private bool $assetCollectorPrepared = false;

    private function beginTopLevelRender(): void
    {
        $this->assetCollectorPrepared = false;

        if (class_exists(\Semitexa\Ssr\Asset\AssetCollectorStore::class)) {
            \Semitexa\Ssr\Asset\AssetCollectorStore::reset();
        }
    }

    /**
     * Populate the per-request AssetCollector with global and module-scoped assets.
     * Idempotent — safe to call multiple times per request.
     *
     * Module detection: extracts module name from the template path convention
     * @project-layouts-{ModuleName}/... and auto-requires module-scoped assets.
     */
    private function prepareAssetCollector(?string $template = null): void
    {
        if ($this->assetCollectorPrepared) {
            return;
        }
        $this->assetCollectorPrepared = true;

        if (!class_exists(\Semitexa\Ssr\Asset\AssetCollectorStore::class)) {
            return;
        }

        // Ensure boot has run (in non-Swoole environments like CLI/tests,
        // SwooleBootstrap::WorkerStart is never called)
        \Semitexa\Ssr\Asset\AssetCollector::boot();

        $collector = \Semitexa\Ssr\Asset\AssetCollectorStore::get();
        $collector->requireGlobals();

        // Auto-require module-scoped assets based on the template namespace
        if ($template !== null && preg_match('/@project-layouts-([^\/]+)\//', $template, $matches)) {
            $collector->requireModule($matches[1]);
        }
    }

    private function initFromAttribute(): void
    {
        $class = static::class;
        $cacheKey = $class;
        if (!array_key_exists($cacheKey, self::$attributeCache)) {
            // Walk up the parent chain to find #[AsResource].
            // This is necessary when PayloadFactory creates a dynamic wrapper class
            // (via eval) that extends the real Resource subclass — the wrapper has no
            // attributes of its own, but its parent does.
            $ref = new \ReflectionClass($class);
            $instance = null;
            $attrClass = null;
            while ($ref !== false) {
                $attrs = $ref->getAttributes(AsResource::class);
                if (!empty($attrs)) {
                    $instance = $attrs[0]->newInstance();
                    $attrClass = $ref->getName();
                    break;
                }
                $ref = $ref->getParentClass();
            }
            $cacheKey = $attrClass ?? $class;
            if (!array_key_exists($cacheKey, self::$attributeCache)) {
                self::$attributeCache[$cacheKey] = $instance !== null
                    ? ['handle' => $instance->handle, 'template' => $instance->template]
                    : ['handle' => null, 'template' => null];
            }
        }

        $cached = self::$attributeCache[$cacheKey];
        if (is_string($cached['handle'] ?? null) && $cached['handle'] !== '') {
            $this->setRenderHandle($cached['handle']);
        }
        $this->declaredTemplate = $cached['template'];
    }

    private function applyIsomorphicContext(array $context): array
    {
        $handle = $context['page_handle'] ?? $context['layout_handle'] ?? null;
        if ($handle === null || $handle === '') {
            return $context;
        }

        $config = IsomorphicConfig::fromEnvironment();
        if (!$config->enabled || self::isCrawler($config)) {
            return $context;
        }

        if (DeferredRequestRegistry::getTable() === null) {
            DeferredRequestRegistry::initialize($config);
        }

        $deferredSlots = LayoutSlotRegistry::getDeferredSlots($handle);
        if ($deferredSlots === []) {
            return $context;
        }

        if (!DeferredTemplateRegistry::isInitialized()) {
            DeferredTemplateRegistry::initialize($config);
        }

        $requestId = 'dr_' . bin2hex(random_bytes(12));
        $sessionId = IsomorphicContextStore::getSessionId();
        if ($sessionId === '') {
            $sessionId = 'sse_' . bin2hex(random_bytes(16));
            IsomorphicContextStore::setSessionId($sessionId);
        }

        $slotIds = array_map(static fn ($s) => $s->slotId, $deferredSlots);
        $serializableContext = self::sanitizeDeferredContext($context);
        $bindToken = bin2hex(random_bytes(16));
        $locale = '';
        if (class_exists(\Semitexa\Locale\Context\LocaleContextStore::class)) {
            $locale = \Semitexa\Locale\Context\LocaleContextStore::getLocale();
        }
        DeferredRequestRegistry::store($requestId, $handle, $serializableContext, $slotIds, $bindToken, $locale);

        IsomorphicContextStore::setPageHandle($handle);
        IsomorphicContextStore::setDeferredSlots($deferredSlots);

        $context['__ssr_deferred_slots'] = $deferredSlots;
        $context['__ssr_deferred_request_id'] = $requestId;
        $context['__ssr_deferred_session_id'] = $sessionId;
        $context['__ssr_preload_hints'] = PlaceholderRenderer::renderPreloadHints($deferredSlots);
        $context['__ssr_deferred_manifest'] = PlaceholderRenderer::renderManifest(
            $requestId,
            $sessionId,
            $deferredSlots,
            $bindToken,
        );
        $context['__ssr_runtime_script'] = PlaceholderRenderer::renderRuntimeScript();
        $context['__ssr_handle_attr'] = ' data-ssr-handle="' . htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') . '"';

        return $context;
    }

    /**
     * Populate SEO metadata from the current render context when handlers did not set it explicitly.
     *
     * This keeps page metadata valid even for older pages or future pages that only provide
     * a title/summary/feature context instead of calling seo helpers directly.
     */
    private static function applySeoDefaults(array $context): void
    {
        $title = SeoMeta::getTitle();
        if ($title === '') {
            $title = self::deriveSeoTitle($context);
            if ($title !== '') {
                SeoMeta::setTitle($title);
            }
        }

        if (!SeoMeta::has('og:title') && $title !== '') {
            SeoMeta::setDefault('og:title', $title);
        }

        $description = SeoMeta::get('description');
        if ($description === null) {
            $description = self::deriveSeoDescription($context);
            if ($description !== null) {
                SeoMeta::setDefault('description', $description);
            }
        }

        if (!SeoMeta::has('og:description') && $description !== null) {
            SeoMeta::setDefault('og:description', $description);
        }

        if (!SeoMeta::has('keywords')) {
            $keywords = self::deriveSeoKeywords($context);
            if ($keywords !== []) {
                SeoMeta::tag('keywords', implode(', ', $keywords));
            }
        }

        if (!SeoMeta::has('og:type')) {
            SeoMeta::setDefault('og:type', 'website');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function deriveSeoTitle(array $context): string
    {
        foreach ([
            $context['page_title'] ?? null,
            $context['featureTitle'] ?? null,
            $context['sectionLabel'] ?? null,
            $context['title'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach (['hero', 'feature', 'section'] as $bagKey) {
            if (!isset($context[$bagKey]) || !is_array($context[$bagKey])) {
                continue;
            }

            foreach (['title', 'label', 'name'] as $field) {
                $candidate = $context[$bagKey][$field] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        foreach (['headline', 'entryLine', 'summary', 'sectionSummary', 'infoWhat'] as $field) {
            $candidate = $context[$field] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function deriveSeoDescription(array $context): ?string
    {
        foreach ([
            $context['summary'] ?? null,
            $context['entryLine'] ?? null,
            $context['sectionSummary'] ?? null,
            $context['infoWhat'] ?? null,
            $context['headline'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach (['hero', 'feature', 'section'] as $bagKey) {
            if (!isset($context[$bagKey]) || !is_array($context[$bagKey])) {
                continue;
            }

            foreach (['lede', 'summary', 'title'] as $field) {
                $candidate = $context[$bagKey][$field] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function deriveSeoKeywords(array $context): array
    {
        $keywords = [];

        foreach ([
            $context['featureTitle'] ?? null,
            $context['sectionLabel'] ?? null,
            $context['title'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $keywords[] = trim($candidate);
            }
        }

        foreach (['infoKeywords', 'highlights', 'chips'] as $listKey) {
            if (!isset($context[$listKey]) || !is_array($context[$listKey])) {
                continue;
            }

            foreach ($context[$listKey] as $item) {
                if (is_string($item)) {
                    $item = trim($item);
                    if ($item !== '') {
                        $keywords[] = $item;
                    }
                    continue;
                }

                if (!is_array($item)) {
                    continue;
                }

                foreach (['term', 'title', 'label', 'name', 'eyebrow'] as $field) {
                    if (isset($item[$field]) && is_string($item[$field]) && trim($item[$field]) !== '') {
                        $keywords[] = trim($item[$field]);
                        break;
                    }
                }
            }
        }

        foreach (['features', 'sections', 'products', 'links'] as $listKey) {
            if (!isset($context[$listKey]) || !is_array($context[$listKey])) {
                continue;
            }

            foreach ($context[$listKey] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach (['title', 'label', 'name', 'hint'] as $field) {
                    if (isset($item[$field]) && is_string($item[$field]) && trim($item[$field]) !== '') {
                        $keywords[] = trim($item[$field]);
                        break;
                    }
                }
            }
        }

        $keywords = array_values(array_unique(array_filter($keywords, static fn (string $item): bool => $item !== '')));

        return array_slice($keywords, 0, 12);
    }

    private static function sanitizeDeferredContext(array $context, int $depth = 0): array
    {
        if ($depth > 32) {
            return [];
        }

        $excludedTopLevelKeys = [
            'navSections',
            'featureTree',
            'sections',
            'features',
            'sourceCode',
            'resultPreview',
            'resultPreviewData',
            'resultPreviewTemplate',
            'l2Content',
            'l2ContentData',
            'l2ContentTemplate',
            'l3Content',
            'l3ContentData',
            'l3ContentTemplate',
            'explanation',
            'relatedPayloads',
        ];

        $sanitized = [];
        foreach ($context as $key => $value) {
            if ($depth === 0 && is_string($key) && in_array($key, $excludedTopLevelKeys, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeDeferredContext($value, $depth + 1);
                continue;
            }

            if (is_null($value) || is_scalar($value)) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private static function isCrawler(IsomorphicConfig $config): bool
    {
        if (!$config->crawlerFullRender) {
            return false;
        }

        $ctx = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($ctx === null) {
            return false;
        }

        $userAgent = $ctx[0]->header['user-agent'] ?? '';
        $queryFull = $ctx[0]->get['_ssr_full'] ?? null;

        if ($queryFull === '1') {
            return true;
        }

        $crawlerPatterns = [
            'Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider',
            'YandexBot', 'Sogou', 'facebot', 'ia_archiver', 'Twitterbot',
            'LinkedInBot', 'WhatsApp', 'TelegramBot',
        ];

        foreach ($crawlerPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
