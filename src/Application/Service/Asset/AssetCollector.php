<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Log\StaticLoggerBridge;


/**
 * Collects asset requirements for a single request and resolves them in dependency order.
 *
 * Per-request only. Boot-time manifest discovery now lives in
 * {@see AssetManifestRegistry}; this collector reads declaration metadata from
 * it and owns nothing but the required-set for the request it belongs to.
 *
 * Instance lifetime stays owned by {@see AssetCollectorStore}, which keeps one
 * collector per coroutine. The static members below are transitional delegates
 * for callers that still reach for the old class-level API.
 */
final class AssetCollector
{
    /** @worker-scoped Single wired slot holding the boot-time manifest registry. */
    private static ?AssetManifestRegistry $manifests = null;

    /** @var array<string, AssetEntry> Per-request required assets keyed by canonical key */
    private array $required = [];

    /**
     * Raw inline CSS registered for this request, keyed by caller-chosen key.
     * Unlike inline-css entries, these carry their content directly — no
     * physical file behind ModuleAssetRegistry::resolve() — so a module can
     * compile CSS from what this request actually rendered.
     *
     * @var array<string, array{css: string, priority: int}>
     */
    private array $rawInlineCss = [];

    /**
     * Callbacks to run once, right after the page's Twig render completes and
     * before the dynamic-CSS marker is resolved. This is the post-render seam:
     * a Twig extension that accumulated usage during render registers one of
     * these, and inside it compiles + registers via {@see inlineCss()}.
     *
     * @var list<callable(self, string): void> receives (collector, rendered html)
     */
    private array $finalizeCallbacks = [];

    /**
     * URLs handed out by `asset()` for this request.
     *
     * `asset()` answers with a URL string and never touches this collector, so
     * a template writing its own `<link rel="stylesheet" href="{{ asset(...) }}">`
     * is invisible here — and when the same file is also declared scope=global,
     * the page gets it twice with nothing to say so. MEASURED on a consumer:
     * platform-ui/css/full.css fetched twice, same fingerprint, on every page.
     *
     * Recording what asset() resolved is what lets the two halves notice each
     * other. It is a note, not a gate: asset() is also how an <img>, a favicon
     * and a font get their URL, and refusing to emit a link because a URL was
     * mentioned somewhere would break more than it fixed.
     *
     * @var array<string, true>
     */
    private array $directUrls = [];

    /**
     * Render signatures the head has already emitted.
     *
     * The head is rendered before the body, so an asset required from body
     * markup — a component asking for the runtime or the stylesheet it cannot
     * work without — arrives after `asset_head()` is long gone. Scripts are
     * fine: `asset_body()` runs later and picks them up. CSS was not: a `<link>`
     * is forced to the head (R1), the body renderer skips it on purpose, and
     * nothing looked at it again, so it was dropped in silence.
     *
     * Remembering what the head DID emit is what lets the post-render pass tell
     * a late arrival from one it has already printed.
     *
     * @var array<string, true>
     */
    private array $headRendered = [];

    private ?AssetManifestRegistry $registry;

    public function __construct(?AssetManifestRegistry $registry = null)
    {
        $this->registry = $registry;
    }

    /**
     * Resolved on each read rather than captured in the constructor.
     *
     * A collector built before {@see WireCoreInstancesListener} runs would
     * otherwise pin the self-created fallback registry for its entire lifetime,
     * and {@see AssetCollectorStore}'s non-coroutine fallback collector lives for
     * the whole process — so that pin would outlast boot and serve an empty
     * manifest set forever. An explicitly injected registry still wins.
     */
    private function registry(): AssetManifestRegistry
    {
        return $this->registry ?? self::manifests();
    }

    public static function setManifestRegistry(AssetManifestRegistry $registry): void
    {
        self::$manifests = $registry;
    }

    private static function manifests(): AssetManifestRegistry
    {
        return self::$manifests ??= new AssetManifestRegistry();
    }

    /**
     * Require an asset by its canonical key.
     *
     * If the key matches a boot-time declaration, that metadata is used.
     * Otherwise, an ad-hoc entry is inferred from the key format.
     *
     * Dependencies are auto-required recursively.
     *
     * @param string               $key       Canonical asset key ({module}:{type}:{name})
     * @param array<string, mixed> $overrides Optional field overrides
     */
    public function require(string $key, array $overrides = []): self
    {
        if (isset($this->required[$key])) {
            if ($overrides !== []) {
                StaticLoggerBridge::warning('ssr', 'Asset required with conflicting overrides; first registration wins', ['key' => $key]);
            }
            return $this; // Deduplication
        }

        $entry = $this->registry()->get($key) ?? AssetEntry::fromKey($key);

        if ($overrides !== []) {
            $entry = $entry->withOverrides($overrides);
        }

        $this->required[$key] = $entry;

        // Auto-require dependencies
        foreach ($entry->dependencies as $dep) {
            $this->require($dep);
        }

        return $this;
    }

    /**
     * Require all assets declared with scope=module for the given module.
     */
    public function requireModule(string $module): self
    {
        foreach ($this->registry()->getDeclarations() as $key => $entry) {
            if ($entry->module === $module && $entry->scope === 'module') {
                $this->require($key);
            }
        }
        return $this;
    }

    /**
     * Require all assets declared with scope=global.
     */
    public function requireGlobals(): self
    {
        foreach ($this->registry()->getDeclarations() as $key => $entry) {
            if ($entry->scope === 'global') {
                $this->require($key);
            }
        }
        return $this;
    }

    /**
     * Return all required assets in dependency-resolved, priority-sorted order.
     *
     * @return AssetEntry[]
     */
    public function resolve(): array
    {
        return AssetResolver::topologicalSort($this->required);
    }

    /**
     * Register raw CSS content for this request's <head>.
     *
     * Registered before the layout renders {{ asset_head() }}, it is emitted
     * there; registered later (typically from an onFinalize() callback, after
     * the page body has rendered), it lands where asset_head() left the
     * dynamic-CSS marker. Re-registering a key overwrites its content — last
     * write wins, so a compiler can refine what an earlier pass registered.
     */
    public function inlineCss(string $key, string $css, int $priority = 100): self
    {
        $this->rawInlineCss[$key] = ['css' => $css, 'priority' => $priority];

        return $this;
    }

    /**
     * Register a callback for the post-render seam. It runs exactly once, when
     * the rendered page HTML is finalized ({@see AssetRenderer::finalizeDynamicCss()}),
     * receiving this collector and the rendered HTML — scan the HTML, compile,
     * and register the result via {@see inlineCss()}.
     */
    public function onFinalize(callable $callback): self
    {
        $this->finalizeCallbacks[] = $callback;

        return $this;
    }

    /**
     * Drain the finalize callbacks: run each exactly once against the rendered
     * HTML. Draining (rather than iterating in place) keeps a second finalize
     * pass over the same request — a layout render nested in a response
     * render — from running them again.
     */
    public function runFinalizeCallbacks(string $html): void
    {
        $callbacks = $this->finalizeCallbacks;
        $this->finalizeCallbacks = [];

        foreach ($callbacks as $callback) {
            $callback($this, $html);
        }
    }

    /**
     * Drain the raw inline CSS registered so far: priority-sorted (stable for
     * equal priorities), emptied on read so each entry renders exactly once no
     * matter how many render or finalize passes touch this request.
     *
     * @return list<array{key: string, css: string, priority: int}>
     */
    /**
     * Stylesheet URLs this render has already put in the document.
     *
     * Written by the renderer, read by `asset()`. The pair is symmetric so the
     * order in the template does not matter: whichever of the two paths is
     * second is the one that reports.
     *
     * @var array<string, true>
     */
    private array $emittedCssUrls = [];

    /** Note a stylesheet URL the renderer has emitted. */
    public function noteEmittedCss(string $url): void
    {
        $this->emittedCssUrls[self::withoutVersion($url)] = true;
    }

    /** Whether a stylesheet for this URL is already in the document. */
    public function wasEmittedAsCss(string $url): bool
    {
        return isset($this->emittedCssUrls[self::withoutVersion($url)]);
    }

    /** Note a URL that `asset()` handed to a template for this request. */
    public function noteDirectUrl(string $url): void
    {
        $this->directUrls[self::withoutVersion($url)] = true;
    }

    /** Whether `asset()` already handed this URL out by hand. */
    public function wasHandedOutDirectly(string $url): bool
    {
        return isset($this->directUrls[self::withoutVersion($url)]);
    }

    /**
     * The URL without its cache-busting query.
     *
     * Compared without it on purpose: the same file reached through two paths
     * carries the same fingerprint today, but a difference there would be a
     * SECOND defect, and a comparison that missed the duplicate because of it
     * would hide the first one.
     */
    private static function withoutVersion(string $url): string
    {
        $mark = strpos($url, '?');

        return $mark === false ? $url : substr($url, 0, $mark);
    }

    /**
     * Record that the head has emitted these render signatures.
     *
     * @param list<string> $signatures
     */
    public function markHeadRendered(array $signatures): void
    {
        foreach ($signatures as $signature) {
            $this->headRendered[$signature] = true;
        }
    }

    /** Whether the head already printed this one. */
    public function headAlreadyRendered(string $signature): bool
    {
        return isset($this->headRendered[$signature]);
    }

    public function takeRawInlineCss(): array
    {
        $out = [];
        foreach ($this->rawInlineCss as $key => $entry) {
            $out[] = ['key' => $key, 'css' => $entry['css'], 'priority' => $entry['priority']];
        }
        $this->rawInlineCss = [];

        usort($out, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $out;
    }

    /**
     * Reset per-request state. Called between requests in Swoole mode.
     */
    public function reset(): void
    {
        $this->required = [];
        $this->rawInlineCss = [];
        $this->finalizeCallbacks = [];
    }

    /**
     * Check whether a specific asset key has been required in this request.
     */
    public function has(string $key): bool
    {
        return isset($this->required[$key]);
    }

    /**
     * Boot-time initialization. Transitional delegate to the manifest registry.
     */
    public static function boot(): void
    {
        self::manifests()->boot();
    }

    /**
     * Transitional delegate kept for test bootstraps that wire the registry by hand.
     */
    public static function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        self::manifests()->setModuleRegistry($moduleRegistry);
    }

    /**
     * @return array<string, AssetEntry>
     */
    public static function getDeclarations(): array
    {
        return self::manifests()->getDeclarations();
    }

    public static function declare(AssetEntry $entry): void
    {
        self::manifests()->declare($entry);
    }

    /**
     * Reset boot-time state. Used in testing only.
     */
    public static function resetBoot(): void
    {
        self::manifests()->resetBoot();
    }
}
