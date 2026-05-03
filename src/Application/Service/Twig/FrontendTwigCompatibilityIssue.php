<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Twig;

final readonly class FrontendTwigCompatibilityIssue
{
    public function __construct(
        public string $templateName,
        public string $templatePath,
        public int $line,
        public string $construct,
        public string $name,
        public string $message,
    ) {
    }

    /**
     * @return array{
     *   template: string,
     *   path: string,
     *   line: int,
     *   construct: string,
     *   name: string,
     *   message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'template' => $this->templateName,
            'path' => $this->templatePath,
            'line' => $this->line,
            'construct' => $this->construct,
            'name' => $this->name,
            'message' => $this->message,
        ];
    }
}
