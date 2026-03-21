<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Http\Response;

use Semitexa\Ssr\Attributes\AsSlotResource;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

abstract class HtmlSlotResponse
{
    private static array $attributeCache = [];

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
    private array $clientModules = [];
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
     */
    public function getRenderContext(): array
    {
        return $this->renderContext;
    }

    public function withRenderContext(array $context): static
    {
        $clone = clone $this;
        $clone->renderContext = array_merge($clone->renderContext, $context);

        return $clone;
    }

    public function withClientModules(array $clientModules): static
    {
        if ($clientModules === []) {
            return $this;
        }

        $clone = clone $this;
        $clone->clientModules = array_values(array_unique(array_merge($clone->clientModules, $clientModules)));

        return $clone;
    }

    /**
     * Renders the slot Twig template and returns HTML string.
     * Merges static context, render context, and optional extra context.
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

    public function getClientModules(): array
    {
        return $this->clientModules;
    }

    public function getStaticContext(): array
    {
        return $this->staticContext;
    }

    private function initFromAttribute(): void
    {
        $class = static::class;
        if (!array_key_exists($class, self::$attributeCache)) {
            $ref = new \ReflectionClass($class);
            $attrs = $ref->getAttributes(AsSlotResource::class);
            if (!empty($attrs)) {
                /** @var AsSlotResource $meta */
                $meta = $attrs[0]->newInstance();
                self::$attributeCache[$class] = [
                    'handle'           => $meta->handle,
                    'slot'             => $meta->slot,
                    'template'         => $meta->template,
                    'deferred'         => $meta->deferred,
                    'priority'         => $meta->priority,
                    'cacheTtl'         => $meta->cacheTtl,
                    'skeletonTemplate' => $meta->skeletonTemplate,
                    'mode'             => $meta->mode,
                    'refreshInterval'  => $meta->refreshInterval,
                    'clientModules'    => $meta->clientModules,
                    'context'          => $meta->context,
                ];
            } else {
                self::$attributeCache[$class] = null;
            }
        }

        $cached = self::$attributeCache[$class];
        if ($cached === null) {
            return;
        }

        $this->slotHandle       = $cached['handle'];
        $this->slotName         = $cached['slot'];
        $this->declaredTemplate = $cached['template'];
        $this->deferred         = $cached['deferred'];
        $this->priority         = $cached['priority'];
        $this->cacheTtl         = $cached['cacheTtl'];
        $this->skeletonTemplate = $cached['skeletonTemplate'];
        $this->mode             = $cached['mode'];
        $this->refreshInterval  = $cached['refreshInterval'];
        $this->clientModules    = $cached['clientModules'];
        $this->staticContext    = $cached['context'];
    }
}
