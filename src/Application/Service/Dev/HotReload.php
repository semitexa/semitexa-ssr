<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Dev;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class HotReload
{
    private static bool $enabled = false;
    /** @var list<array{path:string, extensions:list<string>}> */
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
            $twigCacheDir = ModuleTemplateRegistry::getCacheDir();
            self::$watchPaths = [
                [
                    'path' => ProjectRoot::get() . '/src/modules',
                    'extensions' => ['twig', 'html', 'css', 'js'],
                ],
                [
                    'path' => ProjectRoot::get() . '/src/theme',
                    'extensions' => ['twig', 'html', 'css', 'js'],
                ],
            ];
            if (is_string($twigCacheDir) && $twigCacheDir !== '') {
                self::$watchPaths[] = [
                    'path' => $twigCacheDir,
                    'extensions' => ['php'],
                ];
            }
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

        $scan = self::scanForChanges();

        $response->end(json_encode([
            'timestamp' => $scan['timestamp'],
            'files' => $scan['files'],
        ]));
    }

    private static function serveLivereloadScript(Response $response): void
    {
        $response->header('Content-Type', 'application/javascript');

        $script = <<<'JS'
        (function() {
            let lastTimestamp = 0;

            async function check() {
                try {
                    const res = await fetch('/__semitexa_hotreload');
                    const data = await res.json();

                    if (!lastTimestamp) {
                        lastTimestamp = data.timestamp || 0;
                        return;
                    }

                    if (data.timestamp > lastTimestamp) {
                        lastTimestamp = data.timestamp;
                        if (data.files && data.files.length > 0) {
                            console.log('[Semitexa] Files changed:', data.files);
                        }

                        window.location.reload();
                    }
                } catch (e) {}
            }

            setInterval(check, 1000);
        })();
        JS;

        $response->end($script);
    }

    /**
     * @return array{timestamp:int, files:list<string>}
     */
    private static function scanForChanges(): array
    {
        /** @var list<string> $files */
        $files = [];
        $maxMtime = self::$lastReload;

        foreach (self::$watchPaths as $watch) {
            $path = $watch['path'];
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }

                if (!$file->isFile()) {
                    continue;
                }
                $ext = $file->getExtension();
                if (!in_array($ext, $watch['extensions'], true)) {
                    continue;
                }

                $mtime = (int) $file->getMTime();
                if ($mtime > self::$lastReload) {
                    $files[] = $file->getPathname();
                }
                if ($mtime > $maxMtime) {
                    $maxMtime = $mtime;
                }
            }
        }

        self::$lastReload = $maxMtime;

        return [
            'timestamp' => $maxMtime * 1000,
            'files' => $files,
        ];
    }
}
