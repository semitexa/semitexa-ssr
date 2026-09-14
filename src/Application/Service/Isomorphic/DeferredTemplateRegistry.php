<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;
use Twig\Source;
use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;
use Semitexa\Ssr\Application\Service\Twig\FrontendTwigCompatibilityIssue;
use Semitexa\Ssr\Application\Service\Twig\DeferredTemplateCompatibilityValidator;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

final class DeferredTemplateRegistry
{
    /** @var array<string, string> handle::slot_id => public URL path (e.g. /assets/ssr/tpl/sidebar.a1b2c3.twig) */
    private static array $publishedPaths = [];

    private static bool $initialized = false;

    public static function initialize(?IsomorphicConfig $config = null, ?string $tenantId = null): void
    {
        $config ??= IsomorphicConfig::fromEnvironment();

        if (!$config->enabled) {
            return;
        }

        if ($tenantId !== null && $tenantId !== '' && !preg_match('/\A[a-zA-Z0-9_-]+\z/', $tenantId)) {
            throw new \InvalidArgumentException('Invalid tenant ID.');
        }

        self::$publishedPaths = [];

        $deferredSlots = LayoutSlotRegistry::getAllDeferredSlots();
        $projectRoot = ProjectRoot::get();
        $basePath = rtrim($config->templateAssetsPath, '/');
        $publicBase = self::publicBasePath($basePath);
        $assetBasePath = rtrim(dirname($basePath), '/');

        $outputDir = $projectRoot . '/' . $basePath;
        if ($tenantId !== null && $tenantId !== '') {
            $outputDir .= '/' . $tenantId;
        }

        if (!is_dir($outputDir)) {
            $created = @mkdir($outputDir, 0755, true);
            if (!$created && !is_dir($outputDir)) {
                StaticLoggerBridge::warning('ssr', 'Deferred template publishing skipped: unable to create output directory', [
                    'directory' => $outputDir,
                ]);
                return;
            }
        }

        if ($assetBasePath !== '' && $assetBasePath !== '.' && $assetBasePath !== $basePath) {
            $assetRoot = $projectRoot . '/' . $assetBasePath;
            ModuleAssetRegistry::registerAlias('ssr', $assetRoot);
        }

        foreach ($deferredSlots as $slot) {
            if ($slot->mode !== 'template') {
                continue;
            }

            $templatePath = self::resolveTemplatePath($slot->templateName);
            if ($templatePath === null) {
                continue;
            }

            $content = file_get_contents($templatePath);

        // PUBLISHING IS THE PROMISE, so it is where the promise is checked.
        //
        // A deferred slot template is rendered TWICE — by Twig on the server
        // and by semitexa-twig.js on the client — and the client subset has no
        // functions, no filters beyond |raw and no ternary. Anything outside it
        // renders as an EMPTY STRING with no error, so the divergence is
        // invisible until someone notices missing text.
        //
        // ai:verify already runs lint:deferred-twig when a template changes,
        // which catches this for anyone working in this workspace. It does not
        // catch a consumer project editing its own template and never running
        // the linter — and this is the one moment the framework itself says
        // "the client can render this".
        //
        // Checked HERE and not in the render path: publishSlot() runs once per
        // slot and page, behind ensurePublishedPath()'s cache, so the cost is
        // paid once per worker rather than per request.
        //
        // DEV THROWS, PRODUCTION DOES NOT. A developer wants to be stopped; an
        // end user should not get a 500 for a template that merely degrades,
        // and a template already in production has already shipped. Production
        // logs it once, at the same moment, with the same detail.
        if ($content !== false) {
            self::assertClientCanRender($slot->templateName, $templatePath, $content);
        }
            if ($content === false) {
                continue;
            }

            $hash = substr(md5($content), 0, 8);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $slot->slotId);
            $filename = "{$safeName}.{$hash}.twig";

            $outputFile = $outputDir . '/' . $filename;
            if (is_file($outputFile)) {
                $existing = file_get_contents($outputFile);
                if ($existing === false) {
                    continue;
                }

                if ($existing !== $content && file_put_contents($outputFile, $content) === false) {
                    continue;
                }
            } elseif (file_put_contents($outputFile, $content) === false) {
                continue;
            }

            $urlBase = $publicBase === '' ? '' : '/' . $publicBase;
            if ($tenantId !== null && $tenantId !== '') {
                $urlBase .= '/' . $tenantId;
            }
            self::$publishedPaths[self::keyFor($slot->slotId, $slot->pageHandle)] = $urlBase . '/' . $filename;
        }

        self::$initialized = true;
    }

    public static function getPublishedPath(string $slotId, ?string $pageHandle = null): ?string
    {
        if ($pageHandle !== null && $pageHandle !== '') {
            return self::$publishedPaths[self::keyFor($slotId, $pageHandle)] ?? null;
        }
        $direct = self::$publishedPaths[$slotId] ?? null;
        if ($direct !== null) {
            return $direct;
        }

        $slotKey = '::' . strtolower($slotId);
        foreach (self::$publishedPaths as $key => $path) {
            if (str_ends_with($key, $slotKey)) {
                return $path;
            }
        }
        return null;
    }

    public static function ensurePublishedPath(
        string $slotId,
        string $pageHandle,
        ?IsomorphicConfig $config = null,
        ?string $tenantId = null,
    ): ?string {
        $existing = self::getPublishedPath($slotId, $pageHandle);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $config ??= IsomorphicConfig::fromEnvironment();
        if (!$config->enabled) {
            return null;
        }

        foreach (LayoutSlotRegistry::getDeferredSlots($pageHandle) as $slot) {
            if (
                $slot->mode !== 'template'
                || strcasecmp($slot->slotId, $slotId) !== 0
            ) {
                continue;
            }

            return self::publishSlot($slot, $config, $tenantId);
        }

        return null;
    }

    /**
     * @return array<string, string> slot_id => public URL path
     */
    public static function getAllPublishedPaths(): array
    {
        return self::$publishedPaths;
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    private static function resolveTemplatePath(string $templateName): ?string
    {
        try {
            $loader = ModuleTemplateRegistry::getLoader();
            $source = $loader->getSourceContext($templateName);
            return $source->getPath();
        } catch (\Throwable) {
            // Template source resolution is best-effort — may not exist yet
            return null;
        }
    }

    public static function reset(): void
    {
        self::$publishedPaths = [];
        self::$initialized = false;
    }

    private static function keyFor(string $slotId, string $pageHandle): string
    {
        return strtolower($pageHandle) . '::' . strtolower($slotId);
    }

    /**
     * Refuse — or in production, report — a deferred template the client cannot
     * render.
     *
     * @throws DeferredRenderingException in dev, so the gap is impossible to miss
     */
    private static function assertClientCanRender(string $templateName, string $templatePath, string $source): void
    {
        try {
            $issues = (new DeferredTemplateCompatibilityValidator())
                ->validateSource(new Source($source, $templateName, $templatePath));
        } catch (\Throwable) {
            // The checker failing must never stop a page from publishing.
            return;
        }

        self::reportIncompatibleTemplate($templateName, $issues);
    }

    /**
     * What to DO about issues, separated from finding them.
     *
     * Detection needs a booted module registry — ModuleTemplateRegistry::getTwig()
     * — which is always true where publishSlot() runs and never true in a unit
     * test. Keeping the decision here means the dev/prod asymmetry can be tested
     * on its own, while the finding stays covered by the validator's own tests.
     *
     * @param list<FrontendTwigCompatibilityIssue> $issues
     * @throws DeferredRenderingException in dev, so the gap is impossible to miss
     */
    private static function reportIncompatibleTemplate(string $templateName, array $issues): void
    {
        if ($issues === []) {
            return;
        }

        $detail = array_map(
            static fn (FrontendTwigCompatibilityIssue $i): string => sprintf(
                '%s:%d %s "%s" — %s',
                $i->templateName,
                $i->line,
                $i->construct,
                $i->name,
                $i->message,
            ),
            $issues,
        );

        if (strtolower((string) Environment::getEnvValue('APP_ENV', 'prod')) !== 'dev') {
            StaticLoggerBridge::warning('ssr', 'Deferred template uses constructs the client cannot render', [
                'template' => $templateName,
                'issues' => $detail,
            ]);

            return;
        }

        throw new DeferredRenderingException(sprintf(
            "Deferred template %s uses %d construct(s) the client renderer does not support, "
            . "so those regions would render as an empty string with no error:\n  - %s\n"
            . 'Run bin/semitexa lint:deferred-twig to see the whole picture.',
            $templateName,
            count($issues),
            implode("\n  - ", $detail),
        ));
    }

    private static function publishSlot(
        \Semitexa\Ssr\Domain\Model\DeferredSlotDefinition $slot,
        IsomorphicConfig $config,
        ?string $tenantId = null,
    ): ?string {
        $projectRoot = ProjectRoot::get();
        $basePath = rtrim($config->templateAssetsPath, '/');
        $publicBase = self::publicBasePath($basePath);
        $assetBasePath = rtrim(dirname($basePath), '/');

        $outputDir = $projectRoot . '/' . $basePath;
        if ($tenantId !== null && $tenantId !== '') {
            $outputDir .= '/' . $tenantId;
        }

        if (!is_dir($outputDir)) {
            $created = @mkdir($outputDir, 0755, true);
            if (!$created && !is_dir($outputDir)) {
                StaticLoggerBridge::warning('ssr', 'Deferred template publishing skipped: unable to create output directory', [
                    'directory' => $outputDir,
                ]);
                return null;
            }
        }

        if ($assetBasePath !== '' && $assetBasePath !== '.' && $assetBasePath !== $basePath) {
            $assetRoot = $projectRoot . '/' . $assetBasePath;
            ModuleAssetRegistry::registerAlias('ssr', $assetRoot);
        }

        $templatePath = self::resolveTemplatePath($slot->templateName);
        if ($templatePath === null) {
            return null;
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            return null;
        }

        $hash = substr(md5($content), 0, 8);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $slot->slotId);
        $filename = "{$safeName}.{$hash}.twig";
        $outputFile = $outputDir . '/' . $filename;

        if (is_file($outputFile)) {
            $existing = file_get_contents($outputFile);
            if ($existing === false) {
                return null;
            }

            if ($existing !== $content && file_put_contents($outputFile, $content) === false) {
                return null;
            }
        } elseif (file_put_contents($outputFile, $content) === false) {
            return null;
        }

        $urlBase = $publicBase === '' ? '' : '/' . $publicBase;
        if ($tenantId !== null && $tenantId !== '') {
            $urlBase .= '/' . $tenantId;
        }

        $path = $urlBase . '/' . $filename;
        self::$publishedPaths[self::keyFor($slot->slotId, $slot->pageHandle)] = $path;

        return $path;
    }

    private static function publicBasePath(string $basePath): string
    {
        if ($basePath === 'public') {
            return '';
        }

        if (!str_starts_with($basePath, 'public/')) {
            throw new \InvalidArgumentException(sprintf(
                'Deferred template assets path must point inside public/; got "%s".',
                $basePath,
            ));
        }

        return trim(substr($basePath, strlen('public/')), '/');
    }
}
