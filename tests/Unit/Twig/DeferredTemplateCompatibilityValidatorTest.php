<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Twig\DeferredTemplateCompatibilityValidator;
use Twig\Source;

final class DeferredTemplateCompatibilityValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleTemplateRegistry::reset();
        ModuleTemplateRegistry::setModuleRegistry(new ModuleRegistry());
        TwigExtensionRegistry::setClassDiscovery(new ClassDiscovery());
    }

    public function testValidateSourceAllowsSupportedDeferredSubset(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
{% set items_copy = [1, 2] %}
{% if foo.bar and item in items_copy %}
  {% for key, value in items %}
    {{ value|raw }}
  {% endfor %}
{% endif %}
TWIG,
            'inline-supported',
            '/tmp/inline-supported.twig'
        ));

        self::assertSame([], $issues);
    }

    public function testValidateSourceFlagsUnsupportedFunctionsAndFilters(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
{{ asset('app.js') }}
{{ title|lower }}
TWIG,
            'inline-unsupported-calls',
            '/tmp/inline-unsupported-calls.twig'
        ));

        $names = array_map(static fn ($issue): string => $issue->name, $issues);

        self::assertContains('asset', $names);
        self::assertContains('lower', $names);
    }

    public function testValidateSourceAllowsImplicitEscapedOutput(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
<p>{{ title }}</p>
TWIG,
            'inline-escaped-output',
            '/tmp/inline-escaped-output.twig'
        ));

        self::assertSame([], $issues);
    }

    public function testValidateSourceFlagsExplicitEscapeFilter(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
<p>{{ title|escape }}</p>
TWIG,
            'inline-explicit-escape-output',
            '/tmp/inline-explicit-escape-output.twig'
        ));

        $names = array_map(static fn ($issue): string => $issue->name, $issues);

        self::assertContains('escape', $names);
        self::assertContains('print-expression', $names);
    }

    public function testValidateSourceFlagsUnsupportedSetCaptureAndForElse(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
{% set content %}
  hello
{% endset %}
{% for item in items %}
  {{ item }}
{% else %}
  empty
{% endfor %}
TWIG,
            'inline-unsupported-tags',
            '/tmp/inline-unsupported-tags.twig'
        ));

        $names = array_map(static fn ($issue): string => $issue->name, $issues);

        self::assertContains('set-capture', $names);
        self::assertContains('for-else', $names);
    }
}
