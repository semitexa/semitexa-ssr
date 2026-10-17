<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Readiness;

/**
 * One HTTP answer as an agent sees it: no redirect followed, no session.
 */
final readonly class ProbeResponse
{
    public function __construct(
        public int $status,
        public string $contentType = '',
        public string $body = '',
        public string $location = '',
    ) {}

    public static function unreachable(): self
    {
        return new self(0);
    }

    public function isHtml(): bool
    {
        return str_contains(strtolower($this->contentType), 'text/html');
    }
}
