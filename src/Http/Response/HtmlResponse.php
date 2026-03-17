<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Http\Response;

use Semitexa\Core\Attributes\AsResource;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\Response as CoreResponse;
use Semitexa\Ssr\Seo\SeoMeta;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

class HtmlResponse extends GenericResponse
{
    private ?string $declaredTemplate = null;
    private static array $attributeCache = [];
    private bool $autoRenderEnabled = true;

    public function __construct()
    {
        parent::__construct('', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        SeoMeta::reset();
        $this->initFromAttribute();
    }

    /**
     * Sets the browser tab / SEO title via SeoMeta.
     */
    public function pageTitle(string $title, ?string $suffix = null, ?string $prefix = null): self
    {
        SeoMeta::setTitle($title, $suffix, $prefix);
        return $this;
    }

    /**
     * Sets an arbitrary SEO meta tag via SeoMeta.
     */
    public function seoTag(string $name, string $content): self
    {
        SeoMeta::tag($name, $content);
        return $this;
    }

    /**
     * Adds a single typed variable to the render context.
     * Intended for use by typed with*() methods in Resource subclasses.
     */
    protected function with(string $key, mixed $value): self
    {
        $context = $this->getRenderContext();
        $context[$key] = $value;
        $this->setRenderContext($context);
        return $this;
    }

    /**
     * Renders a Twig template and stores the resulting HTML as response content.
     *
     * When $template is omitted, falls back to the template declared via #[AsResource].
     * When a render handle is set, injects page_handle and layout_handle into context.
     * The $extraContext array is merged on top of the accumulated render context.
     */
    public function renderTemplate(?string $template = null, array $extraContext = []): self
    {
        $tmpl = $template ?? $this->declaredTemplate;
        if ($tmpl === null) {
            throw new \LogicException(
                'No template specified and no #[AsResource] template declared on ' . static::class
            );
        }

        $context = $this->getRenderContext();
        if ($extraContext !== []) {
            $context = array_merge($context, $extraContext);
        }

        $handle = $this->getRenderHandle();
        if ($handle !== null) {
            $context['page_handle'] ??= $handle;
            $context['layout_handle'] ??= $handle;
        }

        $html = ModuleTemplateRegistry::getTwig()->render($tmpl, $context);
        $this->setContent($html);
        return $this;
    }

    public function renderString(string $templateSource, array $context = []): self
    {
        $twig = ModuleTemplateRegistry::getTwig();
        $template = $twig->createTemplate($templateSource);
        $html = $template->render($context);
        $this->setContent($html);
        return $this;
    }

    public function getDeclaredTemplate(): ?string
    {
        return $this->declaredTemplate;
    }

    public function setDeclaredTemplate(?string $template): self
    {
        $this->declaredTemplate = $template;
        return $this;
    }

    public function disableAutoRender(): self
    {
        $this->autoRenderEnabled = false;
        return $this;
    }

    public function enableAutoRender(): self
    {
        $this->autoRenderEnabled = true;
        return $this;
    }

    /**
     * Auto-renders the declared template if no content has been set by the handler pipeline.
     */
    public function toCoreResponse(): CoreResponse
    {
        if (
            $this->autoRenderEnabled
            && $this->getContent() === ''
            && $this->declaredTemplate !== null
            && !in_array($this->getStatusCode(), [204, 304], true)
        ) {
            $this->renderTemplate($this->declaredTemplate);
        }
        return parent::toCoreResponse();
    }

    private function initFromAttribute(): void
    {
        $class = static::class;
        $cacheKey = $class;
        if (!array_key_exists($cacheKey, self::$attributeCache)) {
            // Walk up the parent chain to find #[AsResource].
            // This is necessary when PayloadDtoFactory creates a dynamic wrapper class
            // (via eval) that extends the real Resource subclass — the wrapper has no
            // attributes of its own, but its parent does.
            $ref = new \ReflectionClass($class);
            $instance = null;
            $attrClass = null;
            while ($ref !== false) {
                $attrs = $ref->getAttributes(AsResource::class);
                if (!empty($attrs)) {
                    $instance = $attrs[0]->newInstance();
                    $attrClass = $ref->getName();
                    break;
                }
                $ref = $ref->getParentClass();
            }
            $cacheKey = $attrClass ?? $class;
            if (!array_key_exists($cacheKey, self::$attributeCache)) {
                self::$attributeCache[$cacheKey] = $instance !== null
                    ? ['handle' => $instance->handle, 'template' => $instance->template]
                    : ['handle' => null, 'template' => null];
            }
        }

        $cached = self::$attributeCache[$cacheKey];
        if ($cached['handle'] !== null) {
            $this->setRenderHandle($cached['handle']);
        }
        $this->declaredTemplate = $cached['template'];
    }
}
