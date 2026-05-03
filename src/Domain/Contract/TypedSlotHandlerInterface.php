<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;

/**
 * @template TSlot of HtmlSlotResponse
 * @template TResult of HtmlSlotResponse
 */
interface TypedSlotHandlerInterface
{
    /**
     * @param TSlot $slot
     *
     * @return TResult
     */
    public function handle(object $slot): object;
}
