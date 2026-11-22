<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

use Semitexa\Core\Attribute\AsResource;

#[AsResource(handle: 'hug_protected_page')]
final class HugProtectedPageResource
{
}
