<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Component\ComponentRootAnnotator;

/**
 * One identity, one attribute name: a component root carries
 * data-ui-component / data-ui-component-instance-id whoever renders it, and
 * a template that already set them is not given a second copy.
 */
final class ComponentRootAnnotatorTest extends TestCase
{
    #[Test]
    public function a_script_component_root_gets_its_name_id_and_script(): void
    {
        $html = ComponentRootAnnotator::annotate('<div class="card"><p>x</p></div>', ['name' => 'demo-card', 'script' => 'demo:js:card'], 'uci_abc123');

        self::assertSame(
            '<div class="card" data-ui-component="demo-card" data-ui-component-instance-id="uci_abc123" data-ui-component-script="demo:js:card"><p>x</p></div>',
            $html,
        );
    }

    #[Test]
    public function attributes_the_template_already_set_are_not_repeated(): void
    {
        $html = ComponentRootAnnotator::annotate(
            '<div data-ui-component="demo-card" data-ui-component-instance-id="uci_abc123">x</div>',
            ['name' => 'demo-card', 'script' => 'demo:js:card'],
            'uci_abc123',
        );

        self::assertSame(1, substr_count($html, 'data-ui-component-instance-id='));
        self::assertSame(1, substr_count($html, 'data-ui-component='));
        self::assertStringContainsString('data-ui-component-script="demo:js:card"', $html);
    }
}
