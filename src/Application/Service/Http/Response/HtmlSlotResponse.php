<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Http\Response;

use Semitexa\Ssr\Attribute\AsSlotResource;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

abstract class HtmlSlotResponse
{
    /**
     * The #[AsSlotResource] of each slot class, read once: null for a class
     * that declares none. The attribute itself, typed, rather than an array
     * copied off it whose every key read back as mixed.
     *
     * @var array<class-string, ?AsSlotResource>
     */
    private static array $attributeCache = [];

    /** @var array<string, mixed> */
    protected array $renderContext = [];

    // Metadata resolved from #[AsSlotResource]
    private ?string $slotHandle = null;
    private ?string $slotName = null;
    private ?string $declaredTemplate = null;
    private bool $deferred = false;
    private int $priority = 0;
    private int $cacheTtl = 0;
    private ?string $skeletonTemplate = null;
    private string $mode = 'html';
    private int $refreshInterval = 0;
    /** @var list<string> */
    private array $clientModules = [];
    /** @var array<string, mixed> */
    private array $staticContext = [];

    public function __construct()
    {
        $this->initFromAttribute();
    }

    /**
     * Adds a typed variable to the render context.
     * Intended for use by typed with*() methods in slot resource subclasses.
     */
    protected function with(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->renderContext[$key] = $value;
        return $clone;
    }

    /**
     * Returns the accumulated render context for template rendering.
     *
     * @return array<string, mixed>
     */
    public function getRenderContext(): array
    {
        return $this->renderContext;
    }

    /**
     * Template variables to add, later ones winning. Only named entries are
     * variables: an integer key names none (array_merge renumbered it onto
     * the end of the context, where no template could reach it).
     *
     * @param array<mixed> $context
     *
     * @api
     */
    public function withRenderContext(array $context): static
    {
        $clone = clone $this;
        foreach ($context as $name => $value) {
            if (is_string($name)) {
                $clone->renderContext[$name] = $value;
            }
        }

        return $clone;
    }

    /**
     * @param array<mixed> $clientModules module names; anything not a non-empty string is dropped
     *
     * @api
     */
    public function withClientModules(array $clientModules): static
    {
        if ($clientModules === []) {
            return $this;
        }

        $clone = clone $this;
        $normalizedClientModules = array_values(array_filter(
            array_merge($clone->clientModules, $clientModules),
            static fn (mixed $module): bool => is_string($module) && $module !== '',
        ));
        $clone->clientModules = array_values(array_unique($normalizedClientModules));

        return $clone;
    }

    /**
     * Renders the slot Twig template and returns HTML string.
     * Merges static context, render context, and optional extra context.
     *
     * @api
     */
    public function renderTemplate(?string $template = null): string
    {
        $tmpl = $template ?? $this->declaredTemplate;
        if ($tmpl === null) {
            throw new \LogicException(
                'No template specified and no #[AsSlotResource] template declared on ' . static::class
            );
        }

        $context = array_merge($this->staticContext, $this->renderContext);

        return ModuleTemplateRegistry::getTwig()->render($tmpl, $context);
    }

    public function getSlotHandle(): string
    {
        return $this->slotHandle ?? '';
    }

    public function getSlotName(): string
    {
        return $this->slotName ?? '';
    }

    public function getDeclaredTemplate(): ?string
    {
        return $this->declaredTemplate;
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    public function getSkeletonTemplate(): ?string
    {
        return $this->skeletonTemplate;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getRefreshInterval(): int
    {
        return $this->refreshInterval;
    }

    /** @return list<string> */
    public function getClientModules(): array
    {
        return $this->clientModules;
    }

    /** @return array<string, mixed> */
    public function getStaticContext(): array
    {
        return $this->staticContext;
    }

    private function initFromAttribute(): void
    {
        $class = static::class;
        if (!array_key_exists($class, self::$attributeCache)) {
            $attrs = (new \ReflectionClass($class))->getAttributes(AsSlotResource::class);
            self::$attributeCache[$class] = $attrs === [] ? null : $attrs[0]->newInstance();
        }

        $meta = self::$attributeCache[$class];
        if ($meta === null) {
            return;
        }

        $this->slotHandle       = $meta->handle;
        $this->slotName         = $meta->slot;
        $this->declaredTemplate = $meta->template;
        $this->deferred         = $meta->deferred;
        $this->priority         = $meta->priority;
        $this->cacheTtl         = $meta->cacheTtl;
        $this->skeletonTemplate = $meta->skeletonTemplate;
        $this->mode             = $meta->mode;
        $this->refreshInterval  = $meta->refreshInterval;
        $this->clientModules    = $meta->clientModules;
        $this->staticContext    = $meta->context;
    }
}
