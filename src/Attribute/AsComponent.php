<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/** @api */
#[Capability(
    id: 'ssr.component',
    summary: 'A reusable server-rendered component with its own template and optional client script; Platform UI makes it interactive (#[UiPart] + #[UiOn]).',
    useWhen: 'The same block of markup appears on more than one page, or a block needs its own script; for server-handled events add Platform UI #[UiOn].',
    avoidWhen: 'The markup appears once and carries no behaviour - a plain template fragment is cheaper to read.',
    replaces: [
        'the same Twig include copied across several templates',
        'per-page <script> tags wiring one block by hand',
    ],
    usage: <<<'USAGE'
        #[AsComponent(name: 'shop.card', template: '@project-layouts-Shop/card.html.twig')]
        final class CardComponent {}

        // In any template; the props are the template's context:
        {{ component('shop.card', {title: 'Orders'}) }}

        // Own data comes from #[WithDataProvider(CardData::class)], an #[AsService] implementing
        // DataProviderInterface::resolve(DataProviderContext $context, array $hint = []): array.
        // A component that reacts to the visitor needs #[UiPart] and #[UiOn], and
        // {{ ui_page_sse_session_meta() }} on the page. Before writing one, check what exists
        // with ai:ask components: a form, grid, table or card is already there.
        USAGE,
)]
#[Attribute(Attribute::TARGET_CLASS)]
class AsComponent
{
    public function __construct(
        public string $name,
        public ?string $template = null,
        public ?string $layout = null,
        public bool $cacheable = true,
        public ?string $script = null,
    ) {}
}
