<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

use Semitexa\Core\Attribute\AsResource;

/** A protected page's resource naming no handle: discovery derives `hug-account` from the class name. */
#[AsResource]
final class HugAccountResponse
{
}
