<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

use Psr\Container\ContainerInterface;

/** A container holding exactly what it was given. */
final class HugArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private readonly array $services)
    {
    }

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new \LogicException($id . ' is not in the probe container');
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
