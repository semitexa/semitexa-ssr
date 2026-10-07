<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Ssr\Domain\Exception\UnknownComponentException;

/**
 * Finds the component names templates ask for that nothing declares.
 *
 * Reads `component('literal')` calls in Twig source. A name built at runtime
 * (`component(name)`) cannot be checked statically and is left to the
 * renderer, which refuses it in development. Text that is not template code —
 * `{% verbatim %}` blocks and `{# comments #}` — is skipped: a code sample on
 * a page that SHOWS a component call does not render one. A template it
 * cannot read is reported too: a file the lint could not open is not a file
 * it cleared.
 */
final class ComponentReferenceScanner
{
    private const CALL = '/\bcomponent\(\s*([\'"])([^\'"]+)\1/';

    /**
     * @param list<string> $templateRoots directories to scan for *.twig
     * @param list<string> $known         every registered component name
     * @return list<array{path: string, line: int, name: string, suggestion: ?string, unreadable: bool}>
     */
    public function unknownReferences(array $templateRoots, array $known): array
    {
        $knownSet = array_fill_keys($known, true);
        $issues = [];
        foreach ($this->templates($templateRoots) as $path) {
            $source = @file_get_contents($path);
            if (!is_string($source)) {
                $issues[] = ['path' => $path, 'line' => 0, 'name' => '', 'suggestion' => null, 'unreadable' => true];
                continue;
            }
            foreach ($this->references($source) as [$name, $line]) {
                if (!isset($knownSet[$name])) {
                    $issues[] = [
                        'path' => $path,
                        'line' => $line,
                        'name' => $name,
                        'suggestion' => UnknownComponentException::closest($name, $known),
                        'unreadable' => false,
                    ];
                }
            }
        }

        return $issues;
    }

    /** @return list<array{0: string, 1: int}> name and line of every literal component() call */
    public function references(string $source): array
    {
        // Blank out what is not template code, keeping newlines so lines still count.
        $code = preg_replace_callback(
            '/\{%-?\s*verbatim\s*-?%\}.*?\{%-?\s*endverbatim\s*-?%\}|\{#.*?#\}/s',
            static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? '',
            $source,
        ) ?? $source;

        $found = [];
        if (preg_match_all(self::CALL, $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[2] as [$name, $offset]) {
                $found[] = [$name, substr_count($code, "\n", 0, $offset) + 1];
            }
        }

        return $found;
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function templates(array $roots): array
    {
        $paths = [];
        foreach (array_unique($roots) as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig')) {
                    $paths[] = $file->getPathname();
                }
            }
        }
        sort($paths);

        return $paths;
    }
}
