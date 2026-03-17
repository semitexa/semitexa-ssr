<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Layout;

use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Context\IsomorphicContextStore;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

class LayoutRenderer
{
    private static ?IsomorphicConfig $config = null;

    public static function renderHandle(string $handle, array $context = []): string
    {
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
            if ($config->enabled && !self::isCrawler()) {
                $deferredSlots = LayoutSlotRegistry::getDeferredSlots($handle);

                if ($deferredSlots !== []) {
                    $requestId = 'dr_' . bin2hex(random_bytes(12));
                    $sessionId = IsomorphicContextStore::getSessionId();
                    if ($sessionId === '') {
                        $sessionId = uniqid('sse_', true);
                        IsomorphicContextStore::setSessionId($sessionId);
                    }

                    // Store deferred request context in Swoole Table
                    $slotIds = array_map(static fn ($s) => $s->slotId, $deferredSlots);
                    DeferredRequestRegistry::store($requestId, $handle, $context, $slotIds);

                    IsomorphicContextStore::setPageHandle($handle);
                    IsomorphicContextStore::setDeferredSlots($deferredSlots);

                    // Add deferred rendering context to Twig
                    $baseContext['__ssr_deferred_slots'] = $deferredSlots;
                    $baseContext['__ssr_deferred_request_id'] = $requestId;
                    $baseContext['__ssr_deferred_session_id'] = $sessionId;

                    // Generate preload hints, manifest, and runtime script
                    $baseContext['__ssr_preload_hints'] = PlaceholderRenderer::renderPreloadHints($deferredSlots);
                    $baseContext['__ssr_deferred_manifest'] = PlaceholderRenderer::renderManifest($requestId, $sessionId, $deferredSlots);
                    $baseContext['__ssr_runtime_script'] = PlaceholderRenderer::renderRuntimeScript();
                    $baseContext['__ssr_handle_attr'] = ' data-ssr-handle="' . htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') . '"';
                }
            }

            return ModuleTemplateRegistry::getTwig()->render(
                $layout['template'],
                array_merge($baseContext, $context)
            );
        } catch (\Throwable $e) {
            error_log("Error rendering layout '{$handle}': " . $e->getMessage());
            return '<!doctype html><html><head><meta charset="utf-8"><title>'
                . htmlspecialchars($handle)
                . '</title></head><body><main><pre>'
                . htmlspecialchars($e->getMessage())
                . '</pre></main></body></html>';
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


