<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\TransportType;

#[Attribute(Attribute::TARGET_CLASS)]
final class WithTransport
{
    public function __construct(
        public readonly TransportType $mode = TransportType::Http,
        public readonly bool $deferred = false,
    ) {}
}
