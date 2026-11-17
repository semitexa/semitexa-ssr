<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Template;

use Twig\Loader\FilesystemLoader;

/**
 * Twig's filesystem loader, its cache key carrying what the template says
 * ({@see TemplateContentKey}): a changed template compiles to a new class even
 * when it arrives with an older mtime than the compiled copy of the old one.
 */
final class ContentKeyedFilesystemLoader extends FilesystemLoader
{
    private TemplateContentKey $contentKey;

    /** @param string|list<string> $paths */
    public function __construct($paths = [], ?string $rootPath = null)
    {
        parent::__construct($paths, $rootPath);
        $this->contentKey = new TemplateContentKey();
    }

    public function getCacheKey(string $name): string
    {
        $key = parent::getCacheKey($name);
        $path = $this->findTemplate($name, false);

        return $path === null ? $key : $key . ':' . $this->contentKey->of($path);
    }
}
