<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Twig;

use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Core\Http\ScriptTag;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\BlockNode;
use Twig\Node\BlockReferenceNode;
use Twig\Node\BodyNode;
use Twig\Node\DoNode;
use Twig\Node\EmptyNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\AbstractBinary;
use Twig\Node\Expression\BlockReferenceExpression;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\InlinePrint;
use Twig\Node\Expression\ListExpression;
use Twig\Node\Expression\MacroReferenceExpression;
use Twig\Node\Expression\MethodCallExpression;
use Twig\Node\Expression\TempNameExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\Expression\Unary\AbstractUnary;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ForElseNode;
use Twig\Node\ForLoopNode;
use Twig\Node\ForNode;
use Twig\Node\IfNode;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\MacroNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\PrintNode;
use Twig\Node\SetNode;
use Twig\Node\TextNode;
use Twig\Node\WithNode;
use Twig\Source;
use Twig\Template;

final class DeferredTemplateCompatibilityValidator
{
    private FrontendTwigCompatibilityProfile $profile;

    /** @var array<string, FrontendTwigCompatibilityIssue> */
    private array $issues = [];

    /** @var list<array{line_start:int,line_end:int,expression:string}> */
    private array $printExpressions = [];

    public function __construct(?FrontendTwigCompatibilityProfile $profile = null)
    {
        $this->profile = $profile ?? FrontendTwigCompatibilityProfile::createDefault();
    }

    /**
     * @return list<FrontendTwigCompatibilityIssue>
     */
    public function validateAllDeferredTemplates(): array
    {
        $issues = [];
        foreach ($this->discoverDeferredTemplateNames() as $templateName) {
            array_push($issues, ...$this->validateTemplate($templateName));
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function discoverDeferredTemplateNames(): array
    {
        $templateNames = [];

        foreach (LayoutSlotRegistry::getAllDeferredSlots() as $slot) {
            if ($slot->mode !== 'template' || $slot->templateName === '') {
                continue;
            }

            $templateNames[] = $slot->templateName;
        }

        return array_values(array_unique($templateNames));
    }

    /**
     * @return list<FrontendTwigCompatibilityIssue>
     */
    public function validateTemplate(string $templateName): array
    {
        $source = ModuleTemplateRegistry::getLoader()->getSourceContext($templateName);

        return $this->validateSource($source);
    }

    /**
     * @return list<FrontendTwigCompatibilityIssue>
     */
    public function validateSource(Source $source): array
    {
        $this->issues = [];
        $this->printExpressions = $this->extractPrintExpressions($source);
        $twig = ModuleTemplateRegistry::getTwig();

        try {
            $module = $twig->parse($twig->tokenize($source));
        } catch (SyntaxError $e) {
            return [
                new FrontendTwigCompatibilityIssue(
                    templateName: $source->getName(),
                    templatePath: $source->getPath(),
                    line: max(1, $e->getTemplateLine()),
                    construct: 'syntax',
                    name: 'syntax_error',
                    message: $e->getRawMessage(),
                ),
            ];
        }

        $this->validateNode($module, $source, $twig);
        $this->validateInlineScripts($source);

        return array_values($this->issues);
    }

    /**
     * An inline `<script>` inside a template that arrives by SSE.
     *
     * Markup inserted into a live document changes what a script tag means,
     * and none of it is discoverable — it is learned by watching something not
     * work:
     *
     *   - A script parsed out of a fragment and inserted is INERT. It has to
     *     be re-created to run at all, and re-created carrying THIS document's
     *     nonce rather than the one it was parsed with. A page under a strict
     *     CSP therefore works while reporting a blocked script on every swap.
     *   - It will run MORE THAN ONCE per document, so every binding it makes
     *     has to be idempotent. A listener on `document` accumulates silently,
     *     once per arrival.
     *   - `DOMContentLoaded` fired long ago. A real case: an autocomplete
     *     partial bound its fields only on that event and simply stopped
     *     binding for any page that arrived by swap.
     *
     * The framework already answers all three: `#[AsUiBehavior]` plus the
     * behavior runtime's document MutationObserver connect late-arriving
     * markup with no ceremony and no nonce problem, because the code is a
     * module served from its own origin.
     */
    private function validateInlineScripts(Source $source): void
    {
        $code = self::markupOf($source->getCode());

        // The document scanner, not the source pattern: what is left after the
        // Twig tags are blanked IS markup. It sees an opening tag wrapped
        // across lines — ordinary in a hand-formatted template — it does not
        // end a tag on a `>` inside a quoted value, and it does not read the
        // text inside a script body as another tag.
        foreach (ScriptTag::documentTags($code) as $tag) {
            $attributes = $tag['attributes'];

            // A data block is inert by design and a src= script is re-created
            // with its URL intact; neither carries the three consequences.
            if (!ScriptTag::isExecutable($attributes) || ScriptTag::hasSrc($attributes)) {
                continue;
            }

            $this->addIssue(
                $source,
                substr_count($code, "\n", 0, $tag['start']) + 1,
                'inline_script',
                'script',
                'This template can arrive by SSE, and an inline script in markup that arrives later is inert '
                . 'until re-created, then runs once per arrival, and has already missed DOMContentLoaded. '
                . 'Declare the behaviour with #[AsUiBehavior] instead — the behavior runtime connects '
                . 'late-arriving markup, and its code is a module served from its own origin.'
            );
        }
    }

    /**
     * The template with its Twig tags blanked out, leaving the markup.
     *
     * Two reasons, and they pull the same way. A `{# … #}` comment emits
     * nothing, so a `<script>` written inside one is a note about a script and
     * not a script — reported, it teaches people to ignore this check. And a
     * `>` inside `{{ … }}` or `{% … %}` is a comparison, not the end of a tag,
     * which is the only thing the one-line bound was ever guarding against.
     *
     * `{% verbatim %}` is deliberately NOT blanked: its contents are printed,
     * so a script tag in there is a real script element on the page.
     *
     * Blanked, not cut, so the reported line is still the template's.
     */
    private static function markupOf(string $code): string
    {
        $out = $code;

        foreach (self::twigSpans($code) as [$offset, $length]) {
            $out = substr_replace(
                $out,
                preg_replace('/[^\n]/', ' ', substr($code, $offset, $length)) ?? '',
                $offset,
                $length
            );
        }

        return $out;
    }

    /**
     * Where each Twig span starts and how long it is, found by scanning.
     *
     * Quotes are honoured INSIDE a span and ignored outside it, and that
     * distinction is the whole reason this is a scanner rather than two
     * regexes. Blanking every quoted run first — which is what it used to do,
     * to stop `{% set m = '%}<script>go()' %}` ending its span at the quoted
     * `%}` — also treated ordinary prose as code. In
     *
     *     Don't {# <script>go()</script> #} user's page
     *
     * the two apostrophes in `Don't` and `user's` read as one string spanning
     * the comment, so the comment was never found, never blanked, and the
     * script written inside it was reported as an emission the page makes.
     * A check that cries about a note about a script is a check people switch
     * off.
     *
     * A comment is raw text to Twig, so quotes are not honoured inside `{# #}`
     * either — that is what makes the apostrophes above harmless.
     *
     * @return list<array{int, int}> offset and length, in document order
     */
    private static function twigSpans(string $code): array
    {
        $spans = [];
        $length = strlen($code);
        $i = 0;

        while ($i < $length - 1) {
            if ($code[$i] !== '{') {
                $i++;
                continue;
            }

            $opener = $code[$i + 1];
            if ($opener !== '#' && $opener !== '{' && $opener !== '%') {
                $i++;
                continue;
            }

            $closer = $opener === '#' ? '#}' : ($opener === '{' ? '}}' : '%}');
            $quote = null;
            $end = null;
            $j = $i + 2;

            while ($j < $length) {
                $char = $code[$j];

                if ($quote !== null) {
                    // An escaped character cannot close the string.
                    $j += $char === '\\' ? 2 : 1;
                    if (($code[$j - 1] ?? '') === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($opener !== '#' && ($char === '"' || $char === "'")) {
                    $quote = $char;
                    $j++;
                    continue;
                }

                if ($char === $closer[0] && ($code[$j + 1] ?? '') === $closer[1]) {
                    $end = $j + 2;
                    break;
                }

                $j++;
            }

            // Unterminated: not a span, and the `{` may still open a later one.
            if ($end === null) {
                $i++;
                continue;
            }

            $spans[] = [$i, $end - $i];
            $i = $end;
        }

        return $spans;
    }

    private function validateNode(Node $node, Source $source, Environment $twig, bool $allowPrintFilters = false): void
    {
        $line = max(1, $node->getTemplateLine());

        if (
            $node instanceof ModuleNode
            || $node instanceof BodyNode
            || $node instanceof Nodes
            || $node instanceof TextNode
            || $node instanceof IfNode
            || $node instanceof EmptyNode
            || $node instanceof ContextVariable
            || $node instanceof TempNameExpression
            || $node instanceof ForLoopNode
        ) {
            // Structural nodes allowed without extra constraints.
        } elseif ($node instanceof PrintNode) {
            $this->validatePrintNode($node, $source, $line);
            foreach ($node as $child) {
                $this->validateNode($child, $source, $twig, true);
            }

            return;
        } elseif ($node instanceof ForNode) {
            if ($node->hasNode('else')) {
                $this->addIssue($source, $line, 'tag', 'for-else', 'Deferred frontend Twig does not support `{% for %}...{% else %}` blocks.');
            }

            $sequence = $node->hasNode('seq') ? $node->getNode('seq') : null;
            if (!$sequence instanceof AbstractExpression || !$this->isSupportedForIterableExpression($sequence)) {
                $this->addIssue($source, $line, 'expression', 'for-iterable', 'Deferred frontend Twig `for ... in` iterables must be simple context paths.');
            }
        } elseif ($node instanceof SetNode) {
            if ($node->getAttribute('capture') || $node->getAttribute('safe')) {
                $this->addIssue($source, $line, 'tag', 'set-capture', 'Deferred frontend Twig supports only `{% set name = expression %}` assignments.');
            }

            if (count($node->getNode('names')) !== 1) {
                $this->addIssue($source, $line, 'tag', 'set-multi', 'Deferred frontend Twig does not support multi-target or destructuring set assignments.');
            }
        } elseif ($node instanceof ArrayExpression) {
            if (!$node->isSequence()) {
                $this->addIssue($source, $line, 'expression', 'array-map', 'Deferred frontend Twig supports only sequence array literals like `[a, b, c]`.');
            }
        } elseif ($node instanceof GetAttrExpression) {
            $this->validateGetAttrExpression($node, $source, $line);
        } elseif ($node instanceof FilterExpression) {
            $filterName = $node->getAttribute('name');
            if (!is_string($filterName)) {
                $filterName = $node::class;
            }
            $printExpressionSource = $this->peekPrintExpressionSource($line);

            if ($filterName === 'escape' && $this->isImplicitAutoescapeFilter($node, $printExpressionSource)) {
                // Twig autoescape wraps ordinary `{{ value }}` output with an internal
                // escape filter node even though the frontend renderer already escapes
                // plain output by default.
            } elseif ($allowPrintFilters && $filterName === 'raw') {
                // Raw output is supported only for validated print-node expressions.
            } elseif (!$this->profile->supportsFilterName($filterName)) {
                $this->addIssue($source, $line, 'filter', $filterName, sprintf('Filter `%s` is not available in deferred frontend Twig rendering.', $filterName));
            }
        } elseif ($node instanceof FunctionExpression) {
            $functionName = $node->getAttribute('name');
            if (!is_string($functionName)) {
                $functionName = $node::class;
            }

            if (!$this->profile->supportsFunction($functionName)) {
                $this->addIssue($source, $line, 'function', $functionName, sprintf('Function `%s()` is not available in deferred frontend Twig rendering.', $functionName));
            }
        } elseif ($node instanceof TestExpression) {
            // Twig >= 3.28 wraps attribute access in boolean context with an
            // implicit `true` test (TrueTest) at parse time — the construct
            // never appears in the template source, and truthiness is exactly
            // what deferred frontend rendering already applies to conditions.
            if (!$node instanceof \Twig\Node\Expression\Test\TrueTest) {
                $testName = $node->getAttribute('name');
                if (!is_string($testName)) {
                    $testName = $node::class;
                }

                if (!$this->profile->supportsTest($testName)) {
                    $this->addIssue($source, $line, 'test', $testName, sprintf('Test `%s` is not available in deferred frontend Twig rendering.', $testName));
                }
            }
        } elseif ($node instanceof AbstractBinary) {
            if (!$this->profile->supportsBinaryNode($node)) {
                $this->addIssue($source, $line, 'operator', $node::class, sprintf('Operator node `%s` is not supported in deferred frontend Twig rendering.', $node::class));
            }
        } elseif ($node instanceof AbstractUnary) {
            if (!$this->profile->supportsUnaryNode($node)) {
                $this->addIssue($source, $line, 'operator', $node::class, sprintf('Unary node `%s` is not supported in deferred frontend Twig rendering.', $node::class));
            }
        } elseif (
            $node instanceof IncludeNode
            || $node instanceof ImportNode
            || $node instanceof BlockNode
            || $node instanceof BlockReferenceNode
            || $node instanceof MacroNode
            || $node instanceof WithNode
            || $node instanceof DoNode
            || $node instanceof MacroReferenceExpression
            || $node instanceof BlockReferenceExpression
            || $node instanceof MethodCallExpression
            || $node instanceof ArrowFunctionExpression
            || $node instanceof ConditionalExpression
            || $node instanceof InlinePrint
            || $node instanceof ListExpression
            || $node instanceof ForElseNode
        ) {
            $name = $node->getNodeTag();
            if (!is_string($name) || $name === '') {
                $name = $node::class;
            }
            $this->addIssue($source, $line, 'node', $name, sprintf('Node `%s` is not supported in deferred frontend Twig rendering.', $name));
        }

        foreach ($node as $child) {
            $this->validateNode($child, $source, $twig, $allowPrintFilters);
        }
    }

    private function validateGetAttrExpression(GetAttrExpression $node, Source $source, int $line): void
    {
        if ($node->getAttribute('null_safe')) {
            $this->addIssue($source, $line, 'expression', 'null-safe-access', 'Deferred frontend Twig does not support null-safe attribute access.');
        }

        if ($node->hasNode('arguments') && count($node->getNode('arguments')) > 0) {
            $this->addIssue($source, $line, 'expression', 'attribute-call', 'Deferred frontend Twig supports only dotted property access without arguments.');
        }

        $attributeNode = $node->getNode('attribute');
        if (!$attributeNode instanceof \Twig\Node\Expression\ConstantExpression) {
            $this->addIssue($source, $line, 'expression', 'dynamic-attribute', 'Deferred frontend Twig does not support dynamic attribute names.');
        }

        if ($node->getAttribute('type') !== Template::ANY_CALL) {
            $this->addIssue($source, $line, 'expression', 'attribute-type', 'Deferred frontend Twig supports only standard dotted attribute access.');
        }
    }

    private function validatePrintNode(PrintNode $node, Source $source, int $line): void
    {
        $expression = $node->hasNode('expr') ? $node->getNode('expr') : null;
        $printExpressionSource = $this->peekPrintExpressionSource($line);

        if (!$expression instanceof AbstractExpression || !$this->isSupportedPrintExpression($expression, $printExpressionSource)) {
            $this->addIssue($source, $line, 'expression', 'print-expression', 'Deferred frontend Twig print expressions must be simple context paths with an optional `|raw` filter.');
        }
    }

    private function isSupportedPrintExpression(AbstractExpression $expression, ?string $printExpressionSource): bool
    {
        if ($expression instanceof ContextVariable || $expression instanceof GetAttrExpression) {
            return true;
        }

        if ($expression instanceof FilterExpression) {
            $filterName = $expression->getAttribute('name');
            if (!is_string($filterName)) {
                return false;
            }

            if ($filterName === 'escape' && $this->isImplicitAutoescapeFilter($expression, $printExpressionSource)) {
                return $expression->hasNode('node')
                    && $expression->getNode('node') instanceof AbstractExpression
                    && $this->isSupportedPrintExpression($expression->getNode('node'), $printExpressionSource);
            }

            return $filterName === 'raw'
                && is_string($printExpressionSource)
                && str_ends_with(trim($printExpressionSource), '|raw')
                && $expression->hasNode('node')
                && $expression->getNode('node') instanceof AbstractExpression
                && $this->isSupportedPrintExpression($expression->getNode('node'), $printExpressionSource);
        }

        return false;
    }

    private function isSupportedForIterableExpression(AbstractExpression $expression): bool
    {
        return $expression instanceof ContextVariable || $expression instanceof GetAttrExpression;
    }

    private function isImplicitAutoescapeFilter(FilterExpression $expression, ?string $printExpressionSource): bool
    {
        return $expression->hasNode('arguments')
            && count($expression->getNode('arguments')) === 3
            && !$this->isExplicitEscapeInSource($printExpressionSource);
    }

    private function isExplicitEscapeInSource(?string $printExpressionSource): bool
    {
        return is_string($printExpressionSource) && preg_match('/\|\s*escape\s*(\(|$)/', $printExpressionSource) === 1;
    }

    /**
     * @return list<array{line_start:int,line_end:int,expression:string}>
     */
    private function extractPrintExpressions(Source $source): array
    {
        $matches = [];
        preg_match_all('/\{\{-?\s*(.*?)\s*-?\}\}/s', $source->getCode(), $matches, PREG_OFFSET_CAPTURE);

        $printExpressions = [];

        foreach ($matches[0] as $index => $match) {
            if (!isset($matches[1][$index])) {
                continue;
            }

            $fullMatch = (string) $match[0];
            $fullOffset = (int) $match[1];
            $expression = (string) $matches[1][$index][0];

            $lineStart = substr_count(substr($source->getCode(), 0, $fullOffset), "\n") + 1;
            $lineEnd = $lineStart + substr_count($fullMatch, "\n");

            $printExpressions[] = [
                'line_start' => $lineStart,
                'line_end' => $lineEnd,
                'expression' => $expression,
            ];
        }

        return $printExpressions;
    }

    private function peekPrintExpressionSource(int $line): ?string
    {
        foreach ($this->printExpressions as $printExpression) {
            if ($line < $printExpression['line_start'] || $line > $printExpression['line_end']) {
                continue;
            }

            return $printExpression['expression'];
        }

        return null;
    }

    private function addIssue(Source $source, int $line, string $construct, string $name, string $message): void
    {
        $key = implode('|', [$source->getName(), $line, $construct, $name, $message]);

        if (isset($this->issues[$key])) {
            return;
        }

        $this->issues[$key] = new FrontendTwigCompatibilityIssue(
            templateName: $source->getName(),
            templatePath: $source->getPath(),
            line: $line,
            construct: $construct,
            name: $name,
            message: $message,
        );
    }
}
