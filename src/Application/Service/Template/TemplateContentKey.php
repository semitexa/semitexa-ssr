<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Template;

/**
 * What a template says, as a short hash: part of its compiled cache key, so a
 * changed template is a new compiled class whatever its mtime.
 *
 * Twig keeps a compiled template while its source is older than the compiled
 * file (auto_reload compares mtimes). A deploy by `git archive | tar`, `rsync -a`
 * or `cp -a` lands every file with the commit's time, which can be older than
 * the copy the old code compiled a minute before: production served the old
 * template against the new code, a 500, until cache:clear (the Guard project,
 * 2026-11-17, tk-twig-cache-outlives-a-code-update).
 *
 * The hash is read again only when the file's mtime or size changes, so a
 * template costs one stat per lookup once it is known.
 */
final class TemplateContentKey
{
    /** @var array<string, array{stamp: string, hash: string}> */
    private array $known = [];

    public function of(string $path): string
    {
        // PHP keeps the last stat() of a path: read again with nothing stat'd in
        // between, a template rewritten in a long-lived worker kept its old stamp,
        // its old hash and its old compiled class (review, ssr#186).
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            return '';
        }
        $stamp = $stat['mtime'] . ':' . $stat['size'];
        if (($this->known[$path]['stamp'] ?? null) !== $stamp) {
            $hash = @hash_file('xxh128', $path);
            $this->known[$path] = ['stamp' => $stamp, 'hash' => $hash === false ? '' : $hash];
        }

        return $this->known[$path]['hash'];
    }
}
