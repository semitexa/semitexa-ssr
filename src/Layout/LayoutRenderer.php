<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Layout;

use Semitexa\Ssr\Template\ModuleTemplateRegistry;

class LayoutRenderer
{
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
}


