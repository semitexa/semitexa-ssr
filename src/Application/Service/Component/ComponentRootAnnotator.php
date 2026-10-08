<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

/**
 * Marks a rendered component's root element with its identity, so client code
 * can find it: `data-ui-component` (the name), `data-ui-component-instance-id`
 * (the one instance id — what effects target and client mounts receive) and,
 * for a component with a client `script:`, `data-ui-component-script`. An
 * attribute the template already put on its root is left as it is.
 */
final class ComponentRootAnnotator
{
    /**
     * @param array{name: string, script?: ?string} $component
     */
    public static function annotate(string $html, array $component, string $componentId): string
    {
        $wanted = [
            'data-ui-component' => (string) $component['name'],
            'data-ui-component-instance-id' => $componentId,
        ];
        if (($component['script'] ?? null) !== null) {
            $wanted['data-ui-component-script'] = (string) $component['script'];
        }

        return self::annotateWith($html, $wanted);
    }

    /**
     * Add these attributes to the first element's opening tag, each unless the
     * tag already has it.
     *
     * @param array<string, string> $wanted
     */
    public static function annotateWith(string $html, array $wanted): string
    {
        if (!preg_match('/<([a-zA-Z][a-zA-Z0-9:-]*)\b/', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $tagStart = $matches[0][1];
        $tagName = $matches[1][0];
        $tagNameOffset = $matches[1][1];
        $tagEnd = self::findOpeningTagEnd($html, $tagStart + strlen($matches[0][0]));
        if ($tagEnd === null) {
            return $html;
        }

        $openingTag = substr($html, $tagStart, $tagEnd - $tagStart);
        $attributes = [];
        foreach ($wanted as $name => $value) {
            if (preg_match('/\s' . preg_quote($name, '/') . '\s*=/', $openingTag) !== 1) {
                $attributes[] = $name . '="' . self::escapeAttribute($value) . '"';
            }
        }
        if ($attributes === []) {
            return $html;
        }

        $insertionOffset = self::findAttributeInsertionOffset($html, $tagNameOffset + strlen($tagName), $tagEnd);

        return substr($html, 0, $insertionOffset) . ' ' . implode(' ', $attributes) . substr($html, $insertionOffset);
    }

    private static function findOpeningTagEnd(string $html, int $offset): ?int
    {
        $length = strlen($html);
        $quote = null;

        for ($i = $offset; $i < $length; $i++) {
            $char = $html[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '>') {
                return $i;
            }
        }

        return null;
    }

    private static function findAttributeInsertionOffset(string $html, int $start, int $tagEnd): int
    {
        $i = $tagEnd - 1;
        while ($i >= $start && preg_match('/\s/', $html[$i]) === 1) {
            $i--;
        }

        if ($i >= $start && $html[$i] === '/') {
            $beforeSlash = $i - 1;
            if ($beforeSlash < $start || preg_match('/\s/', $html[$beforeSlash]) === 1) {
                return $i;
            }
        }

        return $tagEnd;
    }

    private static function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
