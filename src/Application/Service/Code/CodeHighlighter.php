<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Code;

use Twig\Markup;

/**
 * Server-side code highlighter for the showcase (ported into the shared kit so
 * both the platform site and the framework demo can emit `.code-token--*` spans
 * that ShowcaseKit's code.css styles). PHP is tokenised with the engine
 * tokeniser; other languages use the generic snippet tokeniser.
 */
/**
 * PHP, shell and JSON syntax highlighting for documentation and demo pages.
 *
 * Tokenizer-based rather than regex-based: `token_get_all()` already knows what
 * a heredoc, an attribute or an interpolated string is, and getting those right
 * with patterns is how highlighters end up unmaintainable. Source that will not
 * parse is not an error — it falls back to plain escaped output, because a doc
 * page showing a deliberately broken snippet must still render.
 *
 * Lives here because it had been COPIED: semitexa-demo and semitexa-showcase-kit
 * each carried their own class, 12 of 13 methods byte-identical, 183 statements
 * of tokenizer duplicated verbatim. Neither had drifted, which is exactly why
 * nobody noticed — the next fix would have landed in one and quietly not the
 * other. Both packages already depend on semitexa-ssr, so this is the nearest
 * home they share. Each keeps its own thin wrapper: the demo package registers
 * the Twig functions, showcase-kit adds its `codeBlock()` chrome.
 *
 * Neither copy had a single test. The ones beside this class are the first.
 */
final class CodeHighlighter
{
    private const BUILTIN_TYPE_NAMES = [
        'array',
        'bool',
        'callable',
        'float',
        'int',
        'iterable',
        'mixed',
        'never',
        'null',
        'object',
        'parent',
        'self',
        'static',
        'string',
        'void',
    ];

    private const KEYWORD_TOKEN_NAMES = [
        'T_ABSTRACT',
        'T_ARRAY',
        'T_AS',
        'T_BREAK',
        'T_CALLABLE',
        'T_CASE',
        'T_CATCH',
        'T_CLASS',
        'T_CLONE',
        'T_CONST',
        'T_CONTINUE',
        'T_DECLARE',
        'T_DEFAULT',
        'T_DO',
        'T_ECHO',
        'T_ELSE',
        'T_ELSEIF',
        'T_EMPTY',
        'T_ENDDECLARE',
        'T_ENDFOR',
        'T_ENDFOREACH',
        'T_ENDIF',
        'T_ENDSWITCH',
        'T_ENDWHILE',
        'T_ENUM',
        'T_EVAL',
        'T_EXIT',
        'T_EXTENDS',
        'T_FINAL',
        'T_FINALLY',
        'T_FN',
        'T_FOR',
        'T_FOREACH',
        'T_FUNCTION',
        'T_GLOBAL',
        'T_GOTO',
        'T_IF',
        'T_IMPLEMENTS',
        'T_INCLUDE',
        'T_INCLUDE_ONCE',
        'T_INSTANCEOF',
        'T_INSTEADOF',
        'T_INTERFACE',
        'T_ISSET',
        'T_LIST',
        'T_MATCH',
        'T_NAMESPACE',
        'T_NEW',
        'T_PRINT',
        'T_PRIVATE',
        'T_PROTECTED',
        'T_PUBLIC',
        'T_READONLY',
        'T_REQUIRE',
        'T_REQUIRE_ONCE',
        'T_RETURN',
        'T_STATIC',
        'T_SWITCH',
        'T_THROW',
        'T_TRAIT',
        'T_TRY',
        'T_UNSET',
        'T_USE',
        'T_VAR',
        'T_WHILE',
        'T_YIELD',
        'T_YIELD_FROM',
    ];

    private const LITERAL_TOKEN_NAMES = [
        'T_TRUE',
        'T_FALSE',
        'T_NULL',
    ];


    public function highlightPhp(mixed $source, int $mixedDepth = 0): Markup
    {
        $source = $this->normalizeSource($source);

        if (trim($source) === '') {
            return new Markup('', 'UTF-8');
        }

        if ($mixedDepth === 0 && $this->looksLikeMixedShellAndPhp($source)) {
            return $this->highlightMixedShellAndPhp($source, $mixedDepth + 1);
        }

        if ($this->looksLikeJson($source)) {
            return $this->highlightJson($source);
        }

        if ($this->looksLikeShell($source) && !$this->looksLikePhp($source)) {
            return $this->highlightShell($source, $mixedDepth);
        }

        $syntheticOpenTag = !str_contains($source, '<?');
        $html = '';

        try {
            $tokens = token_get_all($syntheticOpenTag ? "<?php\n" . $source : $source, TOKEN_PARSE);
        } catch (\CompileError) {
            // Source is not valid PHP — fall back to plain escaped output
            return new Markup(htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'UTF-8');
        }

        foreach ($tokens as $index => $token) {
            if (is_string($token)) {
                $html .= $this->renderTextFragment($token, $this->classForOperator($token));
                continue;
            }

            [$id, $text] = $token;
            if ($syntheticOpenTag && $index === 0 && in_array($id, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                continue;
            }
            $class = $this->classForToken($tokens, $index, $id, $text);
            $html .= $this->renderTextFragment($text, $class);
        }

        return new Markup($html, 'UTF-8');
    }

    public function highlightSnippet(mixed $source): Markup
    {
        return $this->highlightPhp($source);
    }

    /**
     * Full `.code-block` markup (ShowcaseKit code.css shape) with highlighted
     * tokens. `$lang` is an optional label hint; highlighting auto-routes
     * (php / json / shell), falling back to escaped text for other languages.
     */

    public function highlightPhpLines(mixed $source): Markup
    {
        $source = $this->normalizeSource($source);

        if ($source === '') {
            return new Markup('', 'UTF-8');
        }

        $syntheticOpenTag = !str_contains($source, '<?');

        try {
            $tokens = token_get_all($syntheticOpenTag ? "<?php\n" . $source : $source, TOKEN_PARSE);
        } catch (\CompileError) {
            $escaped = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '';
            foreach (preg_split("/(\r\n|\n|\r)/", $escaped) ?: [$escaped] as $index => $lineText) {
                $html .= sprintf(
                    '<span class="code-block__line"><span class="code-block__line-number" aria-hidden="true">%d</span><span class="code-block__line-code">%s</span></span>',
                    $index + 1,
                    $lineText,
                );
            }
            return new Markup($html, 'UTF-8');
        }

        $lines = [''];

        foreach ($tokens as $index => $token) {
            if (is_string($token)) {
                $this->appendTextToLines($lines, $token, $this->classForOperator($token));
                continue;
            }

            [$id, $text] = $token;
            if ($syntheticOpenTag && $index === 0 && in_array($id, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                continue;
            }

            $this->appendTextToLines($lines, $text, $this->classForToken($tokens, $index, $id, $text));
        }

        $html = '';
        foreach ($lines as $index => $lineHtml) {
            $lineNumber = is_int($index) ? $index + 1 : 1;
            $html .= sprintf(
                '<span class="code-block__line"><span class="code-block__line-number" aria-hidden="true">%d</span><span class="code-block__line-code">%s</span></span>',
                $lineNumber,
                $lineHtml,
            );
        }

        return new Markup($html, 'UTF-8');
    }

    /**
     * @param array<int, array{0:int,1:string,2?:int}|string> $tokens
     */
    private function classForToken(array $tokens, int $index, int $id, string $text): ?string
    {
        if ($this->isTokenNamed($id, self::KEYWORD_TOKEN_NAMES)) {
            return 'code-token code-token--keyword';
        }

        if ($this->isTokenNamed($id, self::LITERAL_TOKEN_NAMES)) {
            return 'code-token code-token--literal';
        }

        return match ($id) {
            T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG => 'code-token code-token--tag',
            T_ATTRIBUTE => 'code-token code-token--attribute-marker',
            T_COMMENT, T_DOC_COMMENT => 'code-token code-token--comment',
            T_VARIABLE => 'code-token code-token--variable',
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => 'code-token code-token--string',
            T_LNUMBER, T_DNUMBER => 'code-token code-token--number',
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE => 'code-token code-token--name',
            T_STRING => $this->classifyStringToken($tokens, $index, $text),
            T_WHITESPACE => null,
            default => null,
        };
    }

    /**
     * @param array<int, array{0:int,1:string,2?:int}|string> $tokens
     */
    private function classifyStringToken(array $tokens, int $index, string $text): string
    {
        $lower = strtolower($text);

        if (in_array($lower, ['true', 'false', 'null'], true)) {
            return 'code-token code-token--literal';
        }

        if (in_array($lower, self::BUILTIN_TYPE_NAMES, true)) {
            return 'code-token code-token--type';
        }

        $previousTokenId = $this->previousNonWhitespaceTokenId($tokens, $index);
        if ($previousTokenId === T_FUNCTION) {
            return 'code-token code-token--function';
        }

        $nextTokenText = $this->nextNonWhitespaceTokenText($tokens, $index);
        if ($nextTokenText === '(') {
            return 'code-token code-token--function';
        }

        if ($text !== '' && ctype_upper($text[0])) {
            return 'code-token code-token--type';
        }

        return 'code-token code-token--identifier';
    }

    private function classForOperator(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        return match ($text) {
            '(', ')', '[', ']', '{', '}', ',', ';' => 'code-token code-token--punctuation',
            default => 'code-token code-token--operator',
        };
    }

    /**
     * @param list<string> $tokenNames
     */
    private function isTokenNamed(int $id, array $tokenNames): bool
    {
        return in_array(token_name($id), $tokenNames, true);
    }

    /**
     * @param array<int, array{0:int,1:string,2?:int}|string> $tokens
     */
    private function previousNonWhitespaceTokenId(array $tokens, int $index): int|string|null
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_string($token)) {
                if (trim($token) === '') {
                    continue;
                }

                return $token;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            return $token[0];
        }

        return null;
    }

    /**
     * @param array<int, array{0:int,1:string,2?:int}|string> $tokens
     */
    private function nextNonWhitespaceTokenText(array $tokens, int $index): ?string
    {
        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (is_string($token)) {
                if (trim($token) === '') {
                    continue;
                }

                return $token;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            return $token[1];
        }

        return null;
    }

    private function renderTextFragment(string $text, ?string $class): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($class === null) {
            return $escaped;
        }

        return sprintf('<span class="%s">%s</span>', $class, $escaped);
    }

    /**
     * @param array<int|string, string> $lines
     */
    private function appendTextToLines(array &$lines, string $text, ?string $class): void
    {
        $parts = preg_split("/(\r\n|\n|\r)/", $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $part) {
            if ($part === "\n" || $part === "\r" || $part === "\r\n") {
                $lines[] = '';
                continue;
            }

            $lines[array_key_last($lines)] .= $this->renderTextFragment($part, $class);
        }
    }

    private function normalizeSource(mixed $source): string
    {
        if ($source instanceof Markup) {
            return (string) $source;
        }

        if (is_string($source)) {
            return $source;
        }

        if (is_scalar($source) || $source instanceof \Stringable) {
            return (string) $source;
        }

        return '';
    }

    private function looksLikeJson(string $source): bool
    {
        $trimmed = trim($source);
        if ($trimmed === '' || !in_array($trimmed[0], ['{', '['], true)) {
            return false;
        }

        json_decode($trimmed);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function looksLikePhp(string $source): bool
    {
        return preg_match('/(^|\R)\s*(<\?php|#\[|final\s+class|class\s+\w+|interface\s+\w+|trait\s+\w+|enum\s+\w+|public\s+function|protected\s+\$|private\s+\$|return\s+\$|\$\w+)/m', $source) === 1;
    }

    private function looksLikeShell(string $source): bool
    {
        return preg_match('/(^|\R)\s*(curl\b|bin\/[A-Za-z0-9:_-]+|[A-Z][A-Z0-9_]*=|var\/[^\s]+|#\s)/m', $source) === 1;
    }

    private function looksLikeMixedShellAndPhp(string $source): bool
    {
        if (!$this->looksLikePhp($source)) {
            return false;
        }

        return preg_match('/(^|\R)\s*[A-Z][A-Z0-9_]*=/', $source) === 1
            || preg_match('/(^|\R)\s*(bin\/[A-Za-z0-9:_-]+|curl\b|var\/[^\s]+)/m', $source) === 1;
    }

    private function highlightMixedShellAndPhp(string $source, int $mixedDepth = 0): Markup
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $source);
        $chunks = preg_split("/\n{2,}/", $normalized) ?: [$normalized];
        $htmlChunks = [];

        foreach ($chunks as $chunk) {
            $trimmedChunk = trim($chunk);
            if ($trimmedChunk === '') {
                continue;
            }

            if ($this->looksLikeJson($trimmedChunk)) {
                $htmlChunks[] = (string) $this->highlightJson($trimmedChunk);
                continue;
            }

            if ($this->looksLikePhp($trimmedChunk) && !$this->looksLikeShell($trimmedChunk)) {
                $htmlChunks[] = (string) $this->highlightPhp($trimmedChunk, $mixedDepth);
                continue;
            }

            if ($this->looksLikeShell($trimmedChunk) && !$this->looksLikePhp($trimmedChunk)) {
                $htmlChunks[] = (string) $this->highlightShell($trimmedChunk, $mixedDepth);
                continue;
            }

            if ($this->looksLikePhp($trimmedChunk)) {
                $htmlChunks[] = (string) $this->highlightPhp($trimmedChunk, $mixedDepth);
                continue;
            }

            $htmlChunks[] = (string) $this->highlightShell($trimmedChunk, $mixedDepth);
        }

        return new Markup(implode("\n\n", $htmlChunks), 'UTF-8');
    }

    private function highlightShell(string $source, int $mixedDepth = 0): Markup
    {
        if ($mixedDepth === 0 && $this->looksLikeMixedShellAndPhp($source)) {
            return $this->highlightMixedShellAndPhp($source, $mixedDepth + 1);
        }

        $lines = preg_split("/(\r\n|\n|\r)/", $source) ?: [$source];
        $htmlLines = [];

        foreach ($lines as $line) {
            $htmlLines[] = $this->highlightShellLine($line);
        }

        return new Markup(implode("\n", $htmlLines), 'UTF-8');
    }

    private function highlightShellLine(string $line): string
    {
        if ($line === '') {
            return '';
        }

        if (preg_match('/^\s*#/', $line) === 1) {
            return $this->renderTextFragment($line, 'code-token code-token--comment');
        }

        if (preg_match('/^(\s*)([A-Z][A-Z0-9_]*)(=)(.*)$/', $line, $matches) === 1) {
            return $this->renderTextFragment($matches[1], null)
                . $this->renderTextFragment($matches[2], 'code-token code-token--variable')
                . $this->renderTextFragment($matches[3], 'code-token code-token--operator')
                . $this->renderTextFragment($matches[4], 'code-token code-token--string');
        }

        $parts = preg_split('/(\s+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        $html = '';
        $tokenIndex = 0;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (trim($part) === '') {
                $html .= $this->renderTextFragment($part, null);
                continue;
            }

            $class = match (true) {
                $tokenIndex === 0 => 'code-token code-token--function',
                str_starts_with($part, '--') || str_starts_with($part, '-') => 'code-token code-token--keyword',
                str_contains($part, '=') && preg_match('/^[A-Z][A-Z0-9_]*=/', $part) === 1 => 'code-token code-token--variable',
                preg_match('/^["\'].*["\']$/', $part) === 1 => 'code-token code-token--string',
                preg_match('/^\d+(\.\d+)?$/', $part) === 1 => 'code-token code-token--number',
                str_starts_with($part, '/') || str_starts_with($part, 'var/') || str_starts_with($part, 'bin/') || str_contains($part, '.sql') || str_contains($part, '.json') => 'code-token code-token--string',
                default => 'code-token code-token--identifier',
            };

            $html .= $this->renderTextFragment($part, $class);
            $tokenIndex++;
        }

        return $html;
    }

    private function highlightJson(string $source): Markup
    {
        $pattern = '/("(?:\\\\.|[^"\\\\])*")|(-?(?:0|[1-9]\\d*)(?:\\.\\d+)?(?:[eE][+-]?\\d+)?)|\\b(true|false|null)\\b|([{}\\[\\],:])/';
        $parts = preg_split($pattern, $source, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$source];
        $html = '';
        $expectingKey = false;

        foreach ($parts as $part) {
            $class = match (true) {
                preg_match('/^\s+$/', $part) === 1 => null,
                $part === '{' || $part === ',' => 'code-token code-token--punctuation',
                $part === '}' || $part === '[' || $part === ']' => 'code-token code-token--punctuation',
                $part === ':' => 'code-token code-token--punctuation',
                $part !== '' && $part[0] === '"' => $expectingKey ? 'code-token code-token--identifier' : 'code-token code-token--string',
                preg_match('/^-?(?:0|[1-9]\\d*)(?:\\.\\d+)?(?:[eE][+-]?\\d+)?$/', $part) === 1 => 'code-token code-token--number',
                in_array($part, ['true', 'false', 'null'], true) => 'code-token code-token--literal',
                default => null,
            };

            $html .= $this->renderTextFragment($part, $class);

            if ($part === '{' || $part === ',') {
                $expectingKey = true;
                continue;
            }

            if ($part === ':') {
                $expectingKey = false;
            }
        }

        return new Markup($html, 'UTF-8');
    }
}
