<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Code;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Code\CodeHighlighter;

/**
 * Characterization tests for {@see CodeHighlighter}.
 *
 * The first tests this code has ever had. It existed as two byte-identical
 * copies — one in semitexa-demo, one in semitexa-showcase-kit — for 576 lines
 * each, with no coverage on either side; ep-duplication-sweep collapsed them
 * into this class, and these tests are what makes that collapse checkable rather
 * than hopeful.
 *
 * They pin behaviour, not markup detail: which class a token gets, and the
 * properties that would be a real defect if lost — escaping, the parse-failure
 * fallback, and line numbering.
 */
final class CodeHighlighterTest extends TestCase
{
    // ---- safety --------------------------------------------------------

    #[Test]
    public function source_is_escaped_even_when_it_is_valid_php(): void
    {
        // The output is marked is_safe=html in Twig, so anything this class
        // fails to escape goes to the browser as live markup. A string literal
        // holding a <script> is the ordinary case on a documentation page.
        $html = (string) (new CodeHighlighter())->highlightPhp('<?php $x = "<script>alert(1)</script>";');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function unparseable_source_falls_back_to_escaped_plain_text(): void
    {
        // Doc pages deliberately show broken snippets. A ParseError must not
        // reach the page, and the fallback must still escape.
        $html = (string) (new CodeHighlighter())->highlightPhp('<?php function ( { <b>oops</b>');

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
    }

    #[Test]
    public function unparseable_source_still_produces_numbered_lines(): void
    {
        $html = (string) (new CodeHighlighter())->highlightPhpLines("<?php function ( {\nsecond line");

        self::assertStringContainsString('code-block__line-number', $html);
        self::assertSame(2, substr_count($html, 'code-block__line-number'));
    }

    #[Test]
    public function empty_and_whitespace_only_source_render_as_nothing(): void
    {
        $highlighter = new CodeHighlighter();

        self::assertSame('', (string) $highlighter->highlightPhp(''));
        self::assertSame('', (string) $highlighter->highlightPhp("   \n  "));
        self::assertSame('', (string) $highlighter->highlightPhpLines(''));
    }

    // ---- token classification ------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function tokenClasses(): array
    {
        return [
            'keyword' => ['<?php function foo() {}', 'code-token--keyword'],
            'variable' => ['<?php $name = 1;', 'code-token--variable'],
            'string' => ['<?php $a = "hello";', 'code-token--string'],
            'number' => ['<?php $a = 42;', 'code-token--number'],
            'comment' => ['<?php // a remark', 'code-token--comment'],
        ];
    }

    #[Test]
    #[DataProvider('tokenClasses')]
    public function tokens_are_classified(string $source, string $expectedClass): void
    {
        self::assertStringContainsString(
            $expectedClass,
            (string) (new CodeHighlighter())->highlightPhp($source),
        );
    }

    #[Test]
    public function a_snippet_without_an_open_tag_is_still_highlighted(): void
    {
        // Templates pass bare fragments constantly; the highlighter synthesises
        // an opening tag and must not then emit it.
        $html = (string) (new CodeHighlighter())->highlightPhp('$user = new User();');

        self::assertStringContainsString('code-token--variable', $html);
        self::assertStringNotContainsString('&lt;?php', $html);
    }

    // ---- line rendering -------------------------------------------------

    #[Test]
    public function each_source_line_gets_its_own_numbered_row(): void
    {
        $html = (string) (new CodeHighlighter())->highlightPhpLines("<?php\n\$a = 1;\n\$b = 2;");

        self::assertSame(3, substr_count($html, 'code-block__line-number'));
        self::assertStringContainsString('>1<', $html);
        self::assertStringContainsString('>3<', $html);
    }

    #[Test]
    public function line_numbers_are_hidden_from_assistive_technology(): void
    {
        // They are decoration; a screen reader announcing "one two three" over
        // every code block is noise.
        $html = (string) (new CodeHighlighter())->highlightPhpLines('<?php $a = 1;');

        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    // ---- non-PHP sources -------------------------------------------------

    #[Test]
    public function a_shell_block_is_recognised_rather_than_parsed_as_php(): void
    {
        $html = (string) (new CodeHighlighter())->highlightPhp('composer require semitexa/ssr');

        self::assertNotSame('', $html);
        self::assertStringNotContainsString('&lt;?php', $html);
    }

    #[Test]
    public function a_json_block_is_recognised(): void
    {
        $html = (string) (new CodeHighlighter())->highlightPhp('{"name": "semitexa/ssr", "type": "library"}');

        self::assertNotSame('', $html);
        self::assertStringContainsString('semitexa/ssr', $html);
    }

    #[Test]
    public function a_snippet_is_highlighted_the_same_way_as_a_block(): void
    {
        $highlighter = new CodeHighlighter();
        $source = '<?php $a = 1;';

        self::assertSame(
            (string) $highlighter->highlightPhp($source),
            (string) $highlighter->highlightSnippet($source),
        );
    }
}
