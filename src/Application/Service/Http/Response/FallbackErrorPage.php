<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Http\Response;

/**
 * Nuclear fallback error page.
 *
 * Invoked only when the primary error-page render itself failed —
 * theme-base missing, ThemeResolver broken, template lookup threw, Twig crashed.
 * This is the last line of defense: pure PHP, inline HTML, zero dependencies
 * (no Twig, no DI container, no filesystem reads, no theme resolver).
 *
 * If this code cannot run, the runtime itself is broken and the process
 * should be allowed to 500 at a lower layer.
 */
final class FallbackErrorPage
{
    public static function render(int $statusCode, string $reasonPhrase, string $detail = ''): string
    {
        $status = (string) $statusCode;
        $reason = htmlspecialchars($reasonPhrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $detailEscaped = $detail === ''
            ? ''
            : '<p class="detail">' . htmlspecialchars($detail, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$status} — {$reason}</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:560px;margin:3rem auto;padding:1rem;color:#222;background:#fafafa;}
h1{font-size:1.5rem;margin:0 0 1rem;}
p{line-height:1.5;}
.detail{margin-top:2rem;font-size:.85em;color:#888;font-family:ui-monospace,SFMono-Regular,monospace;white-space:pre-wrap;word-break:break-word;}
</style>
</head>
<body>
<h1>{$status} — {$reason}</h1>
<p>Semitexa reached the request, but the error page template itself failed to render.</p>
<p>This is the framework's nuclear fallback — no theme, no Twig, no dependencies. It appears only when the theme layer is broken or absent.</p>
{$detailEscaped}
</body>
</html>
HTML;
    }
}
