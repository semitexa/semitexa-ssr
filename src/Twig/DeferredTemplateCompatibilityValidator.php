<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Twig;

use Semitexa\Ssr\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;
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

        return array_values($this->issues);
    }

    private function validateNode(Node $node, Source $source, Environment $twig): void
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
        } elseif ($node instanceof ForNode) {
            if ($node->hasNode('else')) {
                $this->addIssue($source, $line, 'tag', 'for-else', 'Deferred frontend Twig does not support `{% for %}...{% else %}` blocks.');
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

            if ($filterName === 'escape' && !$this->usesExplicitFilter($source, $line, 'escape')) {
                // Twig autoescape wraps ordinary `{{ value }}` output with an internal
                // escape filter node even though the frontend renderer already escapes
                // plain output by default.
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
            $testName = $node->getAttribute('name');
            if (!is_string($testName)) {
                $testName = $node::class;
            }

            if (!$this->profile->supportsTest($testName)) {
                $this->addIssue($source, $line, 'test', $testName, sprintf('Test `%s` is not available in deferred frontend Twig rendering.', $testName));
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
            $this->validateNode($child, $source, $twig);
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
        if (!$expression instanceof AbstractExpression || !$this->isSupportedPrintExpression($expression, $source, $line)) {
            $this->addIssue($source, $line, 'expression', 'print-expression', 'Deferred frontend Twig print expressions must be simple context paths with an optional `|raw` filter.');
        }
    }

    private function isSupportedPrintExpression(AbstractExpression $expression, Source $source, int $line): bool
    {
        if ($expression instanceof ContextVariable || $expression instanceof GetAttrExpression) {
            return true;
        }

        if ($expression instanceof FilterExpression) {
            $filterName = $expression->getAttribute('name');
            if (!is_string($filterName)) {
                return false;
            }

            if ($filterName === 'escape' && !$this->usesExplicitFilter($source, $line, 'escape')) {
                return $expression->hasNode('node')
                    && $expression->getNode('node') instanceof AbstractExpression
                    && $this->isSupportedPrintExpression($expression->getNode('node'), $source, $line);
            }

            return $filterName === 'raw'
                && $expression->hasNode('node')
                && $expression->getNode('node') instanceof AbstractExpression
                && $this->isSupportedPrintExpression($expression->getNode('node'), $source, $line);
        }

        return false;
    }

    private function usesExplicitFilter(Source $source, int $line, string $filterName): bool
    {
        $lines = preg_split("/\r\n|\n|\r/", $source->getCode()) ?: [];
        $lineText = $lines[$line - 1] ?? '';

        return str_contains($lineText, '|' . $filterName);
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
