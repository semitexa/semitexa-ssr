<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Ssr\Attribute\AsTwigExtension;
use Semitexa\Ssr\Application\Service\Seo\SemanticRenderer;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;
use Twig\Markup;

/**
 * Document-head functions: the page title, arbitrary meta tags, and the JSON-LD
 * block machine readers consume.
 *
 * Moved out of ModuleTemplateCatalog::registerFunctions() by
 * ep-slay-template-catalog. That method already drains {@see TwigExtensionRegistry}
 * into its own environment, so these were bypassing a seam their own host
 * supported — and which eight other packages already use.
 *
 * The `class_exists` guards that used to wrap these registrations are GONE, and
 * the note that kept them is worth preserving because its reasoning was right
 * and its premise was not. It read: SeoMeta and SemanticRenderer are optional
 * collaborators, so a template calling `page_title()` in a build without them
 * should get a missing function naming the template rather than a fatal inside
 * the extension.
 *
 * Both live in `Application/Service/Seo/` — this package. There is no build
 * without them that is not a broken install of semitexa/ssr, and the guards
 * never held up their end anyway: `pageTitle()` calls `SeoMeta::getTitle()`
 * unguarded, so an absent class fataled on the first call regardless of whether
 * the function got registered. A guard that decides registration while the body
 * assumes presence buys nothing.
 *
 * That is the shape `semitexa.explicitOptionalDependency` exists to catch:
 * package absence modelled as a runtime check instead of as a composer
 * requirement. A genuinely optional collaborator belongs behind a contract the
 * container binds, not behind class_exists().
 */
#[AsTwigExtension]
final class SeoTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('page_title', [$this, 'pageTitle']);
        TwigExtensionRegistry::registerFunction('meta', [$this, 'metaTag'], ['is_safe' => ['html']]);
        TwigExtensionRegistry::registerFunction('semantic_head', [$this, 'semanticHead'], ['is_safe' => ['html']]);
    }

    /**
     * The page title, optionally overridden by the caller for this render.
     */
    public function pageTitle(?string $title = null): string
    {
        return SeoMeta::getTitle($title);
    }

    /**
     * One `<meta>` tag. Marked html-safe because it emits markup by design —
     * escaping of the name and content is {@see SeoMeta::tag()}'s job.
     */
    public function metaTag(string $name, ?string $content = null): Markup
    {
        return new Markup(SeoMeta::tag($name, $content), 'UTF-8');
    }

    /**
     * The JSON-LD block. Emitted for machine readers, so it is markup and must
     * not be escaped.
     */
    public function semanticHead(): Markup
    {
        return new Markup(SemanticRenderer::render(), 'UTF-8');
    }
}
