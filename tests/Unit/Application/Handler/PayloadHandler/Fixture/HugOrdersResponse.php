<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

use Semitexa\Core\Attribute\AsResource;

/** A public page's resource naming no handle: discovery derives `hug-orders` from the class name. */
#[AsResource]
final class HugOrdersResponse
{
}
