<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

use Semitexa\Core\Attribute\AsResource;

/** The `html` profile of {@see HugProfiledPagePayload}: the page whose slots are deferred. */
#[AsResource(handle: 'hug_profiled_page')]
final class HugProfiledHtmlResource
{
}
