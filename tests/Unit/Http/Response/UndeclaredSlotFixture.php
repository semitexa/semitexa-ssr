<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Http\Response;

use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;

/** A slot resource with no #[AsSlotResource] (HtmlSlotResponseTest). */
final class UndeclaredSlotFixture extends HtmlSlotResponse
{
}
