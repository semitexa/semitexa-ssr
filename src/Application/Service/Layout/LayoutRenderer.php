<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Context\IsomorphicContextStore;
use Semitexa\Ssr\Context\PageRenderContextStore;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

class LayoutRenderer
{
    private static ?IsomorphicConfig $config = null;

    public static function renderHandle(string $handle, array $context = []): string
    {
        if (class_exists(\Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::class)) {
            \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::reset();
        }

        $layout = ModuleTemplateRegistry::resolveLayout($handle);
        
        if ($layout === null) {
            return '<!doctype html><html><head><meta charset="utf-8"><title>'
                . htmlspecialchars($context['title'] ?? 'Layout missing')
                . '</title></head><body><main><p>Layout handle \''
                . htmlspecialchars($handle)
                . '\' is not activated. Run bin/semitexa layout:generate '
                . htmlspecialchars($handle)
                . '</p></main></body></html>';
        }
        
        try {
            $baseContext = [
                'layout_handle' => $handle,
                'page_handle' => $handle,
                'layout_module' => $layout['module'],
            ];
            if (isset($context['layout_frame'])) {
                $baseContext['layout_frame'] = $context['layout_frame'];
            }

            // Isomorphic deferred rendering support
            $config = self::getConfig();
            $bindToken = '';
            if ($config->enabled && !self::isCrawler()) {
                if (DeferredRequestRegistry::getTable() === null) {
                    DeferredRequestRegistry::initialize($config);
                }

                $deferredSlots = LayoutSlotRegistry::getDeferredSlots($handle);

                if ($deferredSlots !== []) {
                    if (!DeferredTemplateRegistry::isInitialized()) {
                        DeferredTemplateRegistry::initialize($config);
                    }
                    $requestId = 'dr_' . bin2hex(random_bytes(12));
                    $sessionId = IsomorphicContextStore::getSessionId();
                    if ($sessionId === '') {
                        $sessionId = 'sse_' . bin2hex(random_bytes(16));
                        IsomorphicContextStore::setSessionId($sessionId);
                    }

                    // Store deferred request context in Swoole Table. The slot list is narrowed
                    // to actually rendered placeholders after Twig finishes rendering.
                    $slotIds = array_map(static fn ($s) => $s->slotId, $deferredSlots);
                    $bindToken = bin2hex(random_bytes(16));
                    $locale = '';
                    if (class_exists(\Semitexa\Locale\Context\LocaleContextStore::class)) {
                        $locale = \Semitexa\Locale\Context\LocaleContextStore::getLocale();
                    }
                    DeferredRequestRegistry::store($requestId, $handle, $context, $slotIds, $bindToken, $locale);

                    IsomorphicContextStore::setPageHandle($handle);
                    IsomorphicContextStore::setDeferredSlots($deferredSlots);

                    // Add deferred rendering context to Twig
                    $baseContext['__ssr_deferred_slots'] = $deferredSlots;
                    $baseContext['__ssr_deferred_request_id'] = $requestId;
                    $baseContext['__ssr_deferred_session_id'] = $sessionId;

                    // Generate preload hints, manifest, and runtime script
                    $baseContext['__ssr_preload_hints'] = PlaceholderRenderer::renderPreloadHints($deferredSlots);
                    $baseContext['__ssr_deferred_manifest'] = PlaceholderRenderer::renderManifest(
                        $requestId,
                        $sessionId,
                        $deferredSlots,
                        $bindToken,
                    );
                    $baseContext['__ssr_runtime_script'] = PlaceholderRenderer::renderRuntimeScript();
                    $baseContext['__ssr_handle_attr'] = ' data-ssr-handle="' . htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') . '"';
                }
            }

            $mergedContext = array_merge($baseContext, $context);
            PageRenderContextStore::set($mergedContext);

            $html = ModuleTemplateRegistry::getTwig()->render(
                $layout['template'],
                $mergedContext
            );

            $requestId = $baseContext['__ssr_deferred_request_id'] ?? null;
            if (is_string($requestId)) {
                /** @var array<\Semitexa\Ssr\Domain\Model\DeferredSlotDefinition> $deferredSlots */
                $deferredSlots = $baseContext['__ssr_deferred_slots'] ?? [];

                try {
                    $renderedSlots = PlaceholderRenderer::filterRenderedSlotsFromHtml(
                        $html,
                        $deferredSlots
                    );
                    $renderedSlotIds = array_map(static fn ($slot) => $slot->slotId, $renderedSlots);
                    DeferredRequestRegistry::updateSlots($requestId, $renderedSlotIds);

                    $updatedPreloadHints = PlaceholderRenderer::renderPreloadHints($renderedSlots);
                    $updatedManifest = PlaceholderRenderer::renderManifest(
                        $requestId,
                        (string) ($baseContext['__ssr_deferred_session_id'] ?? ''),
                        $renderedSlots,
                        (string) $bindToken,
                    );

                    $html = str_replace((string) ($baseContext['__ssr_preload_hints'] ?? ''), $updatedPreloadHints, $html);
                    $html = str_replace((string) ($baseContext['__ssr_deferred_manifest'] ?? ''), $updatedManifest, $html);
                } catch (\Throwable $e) {
                    StaticLoggerBridge::error('ssr', 'Failed to finalize deferred SSR slots', [
                        'handle' => $handle,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return $html;
        } catch (\Throwable $e) {
            $debugEnabled = filter_var(\Semitexa\Core\Environment::getEnvValue('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN);

            $logContext = [
                'handle' => $handle,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ];

            if ($debugEnabled) {
                $logContext['trace'] = $e->getTraceAsString();
            }

            StaticLoggerBridge::error('ssr', 'Error rendering layout', $logContext);

            if ($debugEnabled) {
                return '<!doctype html><html><head><meta charset="utf-8"><title>'
                    . htmlspecialchars($handle)
                    . '</title></head><body><main><pre>'
                    . htmlspecialchars($e->getMessage())
                    . '</pre></main></body></html>';
            }

            return '<!doctype html><html><head><meta charset="utf-8"><title>Internal Server Error</title>'
                . '</head><body><main><p>An unexpected error occurred.</p></main></body></html>';
        } finally {
            PageRenderContextStore::reset();
        }
    }

    private static function getConfig(): IsomorphicConfig
    {
        if (self::$config === null) {
            self::$config = IsomorphicConfig::fromEnvironment();
        }
        return self::$config;
    }

    /**
     * Detect crawler User-Agents for full synchronous rendering.
     */
    private static function isCrawler(): bool
    {
        $config = self::getConfig();
        if (!$config->crawlerFullRender) {
            return false;
        }

        $ctx = \Semitexa\Core\Server\SwooleBootstrap::getCurrentSwooleRequestResponse();
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

    public static function resetConfig(): void
    {
        self::$config = null;
    }
}
