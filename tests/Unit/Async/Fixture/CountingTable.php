<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async\Fixture;

use Swoole\Table;

/** A shared table that counts the row reads made of it. */
final class CountingTable extends Table
{
    public int $gets = 0;

    /** @return array<string, mixed>|string|int|float|false */
    public function get(string $key, ?string $field = null): array|string|int|float|false
    {
        $this->gets++;
        /** @var array<string, mixed>|string|int|float|false $value */
        $value = parent::get($key, $field);

        return $value;
    }
}
