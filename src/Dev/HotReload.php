<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Dev;

use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class HotReload
{
    private static bool $enabled = false;
    private static array $watchPaths = [];
    private static int $lastReload = 0;

    public static function initialize(): void
    {
        $env = Environment::create();
        
        if ($env->get('APP_ENV') !== 'dev') {
            return;
        }

        self::$enabled = $env->get('HOT_RELOAD', 'true') === 'true';
        
        if (self::$enabled) {
            self::$watchPaths = [
                ProjectRoot::get() . '/src/modules',
                ProjectRoot::get() . '/src/theme',
                ProjectRoot::get() . '/var/cache/twig',
            ];
        }
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function handle(Request $request, Response $response): bool
    {
        if (!self::$enabled) {
            return false;
        }

        $path = $request->server['path_info'] ?? '';

        if ($path === '/__semitexa_hotreload') {
            self::sendReloadEvent($response);
            return true;
        }

        if ($path === '/__semitexa_livereload') {
            self::serveLivereloadScript($response);
            return true;
        }

        return false;
    }

    private static function sendReloadEvent(Response $response): void
    {
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-cache');
        
        $response->end(json_encode([
            'timestamp' => time(),
            'files' => self::getChangedFiles(),
        ]));
    }

    private static function serveLivereloadScript(Response $response): void
    {
        $response->header('Content-Type', 'application/javascript');
        
        $script = <<<'JS'
        (function() {
            let lastTimestamp = Date.now();
            
            async function check() {
                try {
                    const res = await fetch('/__semitexa_hotreload');
                    const data = await res.json();
                    
                    if (data.timestamp > lastTimestamp) {
                        lastTimestamp = data.timestamp;
                        if (data.files && data.files.length > 0) {
                            console.log('[Semitexa] Files changed:', data.files);
                            window.location.reload();
                        }
                    }
                } catch (e) {}
            }
            
            setInterval(check, 1000);
        })();
        JS;
        
        $response->end($script);
    }

    private static function getChangedFiles(): array
    {
        $files = [];
        
        foreach (self::$watchPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $ext = $file->getExtension();
                    if (in_array($ext, ['twig', 'html', 'css', 'js'])) {
                        if ($file->getMTime() > self::$lastReload) {
                            $files[] = $file->getPathname();
                        }
                    }
                }
            }
        }
        
        return $files;
    }
}
