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

    /**
     * The template the last initialize() refused, and the content it was
     * refused for: {template, path, hash, message}.
     *
     * A refusal throws before $initialized is set, and every HTML request
     * that reaches a deferred slot calls initialize() again while it is
     * unset. Without this each of those requests re-read every deferred
     * template and re-parsed the bad one — MEASURED at ~10 KB of worker
     * memory per request, retained by Twig's escaper for good. The request
     * still fails, loudly, with the same message, until the file's CONTENT
     * changes; then it is checked again, so fixing the template is enough in
     * dev. Content, not mtime/size: a same-size fix within one mtime tick
     * must not keep failing, and re-reading one file on a request that is
     * failing anyway is cheap next to re-parsing it.
     *
     * Only strings and ints: nothing from the request that hit it.
     *
     * @var array{template: string, path: string, hash: string, message: string}|null
     */
    private static ?array $refused = null;

    public static function initialize(?IsomorphicConfig $config = null, ?string $tenantId = null): void
    {
        $config ??= IsomorphicConfig::fromEnvironment();

        if (!$config->enabled) {
            return;
        }

        if ($tenantId !== null && $tenantId !== '' && !preg_match('/\A[a-zA-Z0-9_-]+\z/', $tenantId)) {
            throw new \InvalidArgumentException('Invalid tenant ID.');
        }

        self::rethrowIfStillRefused();

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
            if ($content === false) {
                continue;
            }

            // PUBLISHING IS THE PROMISE, so it is where the promise is checked.
            // See assertClientCanRender() for why here and not the render path.
            try {
                self::assertClientCanRender($slot->templateName, $templatePath, $content);
            } catch (DeferredRenderingException $e) {
                self::rememberRefusal($slot->templateName, $templatePath, $content, $e);
                throw $e;
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
        self::$refused = null;
    }

    private static function rememberRefusal(string $templateName, string $templatePath, string $content, DeferredRenderingException $e): void
    {
        self::$refused = [
            'template' => $templateName,
            'path' => $templatePath,
            'hash' => hash('sha256', $content),
            'message' => $e->getMessage(),
        ];
    }

    /**
     * Fail again, without redoing the work, while the refused template's
     * content is untouched. A fresh exception each time: rethrowing the first
     * one would keep its trace — and whatever that trace references — alive.
     *
     * @throws DeferredRenderingException
     */
    private static function rethrowIfStillRefused(): void
    {
        $refused = self::$refused;
        if ($refused === null) {
            return;
        }

        // Gone: nothing left to refuse. initialize() resolves the template
        // afresh and checks whatever it now points at.
        if (!is_file($refused['path'])) {
            self::$refused = null;
            return;
        }

        // Present but unreadable keeps the refusal: initialize() skips a file
        // it cannot read, so clearing here would let it succeed without ever
        // checking the template. Only readable, changed content is a fix.
        $content = @file_get_contents($refused['path']);
        if ($content === false || hash('sha256', $content) === $refused['hash']) {
            throw new DeferredRenderingException($refused['message']);
        }

        self::$refused = null;
    }

    private static function keyFor(string $slotId, string $pageHandle): string
    {
        return strtolower($pageHandle) . '::' . strtolower($slotId);
    }

    /**
     * Refuse — or in production, report — a deferred template the client cannot
     * render.
     *
     * A deferred slot is rendered TWICE: by Twig on the server and by
     * semitexa-twig.js on the client. The client subset has no functions, no
     * filters beyond |raw and no ternary, and anything outside it renders as an
     * EMPTY STRING with no error — so the divergence is invisible until someone
     * notices missing text.
     *
     * CHECKED AT PUBLISH, NOT AT RENDER. Publishing is the moment the framework
     * says "the client can render this", and both publish paths run once per
     * slot — initialize() sweeps them at boot, publishSlot() covers whatever it
     * missed, behind ensurePublishedPath()'s cache. Validating per render would
     * pay an AST walk per request for an answer that cannot change between them.
     *
     * ai:verify already runs lint:deferred-twig when a template changes, so
     * anyone working in this workspace is covered. What is not covered, and what
     * this is for, is a consumer project editing its own template and never
     * running the linter.
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

        // The lazy twin of the check in initialize(): ensurePublishedPath()
        // reaches this for a slot the boot sweep did not cover.
        self::assertClientCanRender($slot->templateName, $templatePath, $content);

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
