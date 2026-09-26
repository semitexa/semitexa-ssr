<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Application\Service\Twig\DeferredTemplateCompatibilityValidator;
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

    /**
     * Twig's escaper visitor keeps every node it analyses for the life of the
     * Environment, so re-parsing an unchanged source on every request grew a
     * dev worker by ~10 KB per call. An unchanged source is answered from
     * what was already found; a changed one is inspected again.
     */
    public function testValidateSourceDoesNotReparseAnUnchangedSource(): void
    {
        $source = new Source("<p>{{ trans('x') }}</p>", 'inline-memo', '/tmp/inline-memo.twig');
        $first = (new DeferredTemplateCompatibilityValidator())->validateSource($source);

        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < 300; $i++) {
            $again = (new DeferredTemplateCompatibilityValidator())->validateSource($source);
        }
        gc_collect_cycles();

        self::assertLessThan(64 * 1024, memory_get_usage() - $before, 'each re-parse is retained by Twig');
        self::assertEquals($first, $again);

        $fixed = (new DeferredTemplateCompatibilityValidator())->validateSource(
            new Source('<p>{{ title }}</p>', 'inline-memo', '/tmp/inline-memo.twig'),
        );
        self::assertNotSame([], $first);
        self::assertSame([], $fixed);
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

    public function testValidateSourceFlagsUnsupportedRawSpacingVariants(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
{{ title | raw }}
{{ title| raw }}
TWIG,
            'inline-raw-spacing-output',
            '/tmp/inline-raw-spacing-output.twig'
        ));

        $printExpressionIssues = array_values(array_filter(
            $issues,
            static fn ($issue): bool => $issue->name === 'print-expression'
        ));

        self::assertCount(2, $printExpressionIssues);
    }

    public function testValidateSourceFlagsRawFilterOutsidePrintNodes(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
{% for item in items|raw %}
  {{ item }}
{% endfor %}
TWIG,
            'inline-raw-non-print',
            '/tmp/inline-raw-non-print.twig'
        ));

        $names = array_map(static fn ($issue): string => $issue->name, $issues);

        self::assertContains('raw', $names);
    }

    public function testValidateSourceFlagsUnsupportedForIterableExpressions(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
{% for item in items and other %}
  {{ item }}
{% endfor %}
TWIG,
            'inline-unsupported-for-iterable',
            '/tmp/inline-unsupported-for-iterable.twig'
        ));

        $names = array_map(static fn ($issue): string => $issue->name, $issues);

        self::assertContains('for-iterable', $names);
    }

    public function testValidateSourceFlagsExplicitEscapeWithArguments(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            <<<'TWIG'
<p>{{ title|escape('html', null, true) }}</p>
TWIG,
            'inline-explicit-escape-with-args-output',
            '/tmp/inline-explicit-escape-with-args-output.twig'
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

    /**
     * A deferred slot's template arrives in a live document over SSE. An
     * inline script in it is inert until re-created, then runs once per
     * arrival, and has already missed DOMContentLoaded — none of which is
     * discoverable. It is learned by watching something not work.
     */
    public function testValidateSourceFlagsAnInlineScriptInADeferredTemplate(): void
    {
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            "<div class=\"card\">{{ title }}</div>\n<script>document.addEventListener('click', go);</script>",
            'inline-script',
            '/tmp/inline-script.twig'
        ));

        self::assertCount(1, $issues);
        self::assertSame('inline_script', $issues[0]->construct);
        self::assertSame(2, $issues[0]->line);
        self::assertStringContainsString('once per arrival', $issues[0]->message);
        self::assertStringContainsString('AsUiBehavior', $issues[0]->message);
    }

    public function testValidateSourceLeavesDataBlocksAndExternalScriptsAlone(): void
    {
        // A data block never executes, and a src= script is re-created with
        // its URL intact. Neither carries the three consequences, and a lint
        // that flagged them would be one people switch off.
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            "<script type=\"application/json\" data-props>{}</script>\n<script src=\"/assets/x.js\"></script>",
            'safe-scripts',
            '/tmp/safe-scripts.twig'
        ));

        self::assertSame([], $issues);
    }

    public function testValidateSourceSeesAnOpeningTagWrittenAcrossLines(): void
    {
        // A wrapped opening tag is formatting, not a different kind of script.
        // The one-line bound belongs to source scanning, where a `>` may be an
        // operator; once the Twig tags are blanked, what is left is markup.
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            "<script\n  type=\"module\"\n  defer>go()</script>",
            'wrapped-script',
            '/tmp/wrapped-script.twig'
        ));

        self::assertCount(1, $issues);
        self::assertSame('inline_script', $issues[0]->construct);
    }

    public function testProseApostrophesDoNotHideATwigComment(): void
    {
        // Quoted runs used to be blanked across the WHOLE template before the
        // Twig spans were found, so the apostrophes in `Don't` and `user's`
        // read as one string spanning the comment between them. The comment
        // was then never found, never blanked, and the script written inside
        // it was reported as an emission the page makes — a lint crying about
        // a note about a script.
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            "Don't {# <script>go()</script> #} read the user's page",
            'prose-apostrophes',
            '/tmp/prose-apostrophes.twig'
        ));

        self::assertSame([], $issues, 'the script lives inside a comment, which emits nothing');
    }

    public function testADelimiterInsideAStringStillDoesNotEndItsSpan(): void
    {
        // The guarantee the old masking existed for, kept after replacing it
        // with a scanner: Twig accepts `%}` inside a string, and reading it as
        // the end of the span reported a script the template never emits.
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            "{% set m = '%}<script>go()' %}after",
            'delimiter-in-string',
            '/tmp/delimiter-in-string.twig'
        ));

        self::assertSame([], $issues);
    }

    public function testValidateSourceDoesNotReportAScriptTagInsideATwigComment(): void
    {
        // A Twig comment emits nothing, so there is no script element to be
        // inert, to run twice, or to have missed DOMContentLoaded. Reporting
        // the note ABOUT the rule is how a lint trains people to ignore it.
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            "{# never do this: <script>go()</script> #}\n<div>{{ title }}</div>",
            'commented-script',
            '/tmp/commented-script.twig'
        ));

        self::assertSame([], $issues);
    }

    public function testValidateSourceStillReportsAScriptInsideVerbatim(): void
    {
        // verbatim is not a comment: its contents are PRINTED, so this really
        // is a script element on the page and carries every consequence.
        $validator = new DeferredTemplateCompatibilityValidator();

        $issues = $validator->validateSource(new Source(
            "{% verbatim %}<script>go()</script>{% endverbatim %}",
            'verbatim-script',
            '/tmp/verbatim-script.twig'
        ));

        self::assertCount(1, $issues);
    }
}
