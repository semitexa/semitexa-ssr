<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\PageTimeline;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidationBusInterface;

/**
 * Default {@see ScopeInvalidationBusInterface} binding. Forwards a data-less
 * PUBLISH to the canonical KISS transport's Redis bus
 * ({@see \Semitexa\Ssr\Application\Service\Async\SseServer::publishScopeInvalidation()}), reusing the
 * existing size-1 SSE pool (a non-blocking request/reply PUBLISH is safe on
 * the pooled connection — only the subscriber's blocking loop needs a
 * dedicated connection, design §C.3). No new SSE endpoint, queue, or stream
 * is introduced; this is publisher-side only — there is no SUBSCRIBE here.
 *
 * Every publish passes through here — a touch() from code and an ORM write
 * alike — so this is where a development tool hears that the server signalled.
 */
#[SatisfiesServiceContract(of: ScopeInvalidationBusInterface::class)]
final class SseRedisScopeInvalidationBus implements ScopeInvalidationBusInterface
{
    #[InjectAsReadonly]
    protected SseServer $sseServer;

    public function publish(string $channel): void
    {
        $this->sseServer->publishScopeInvalidation($channel);

        if (PageTimeline::isOn()) {
            // ui.invalidate.{tenant}.{scope}
            $parts = explode('.', $channel, 4);
            PageTimeline::broadcast('signal', [
                'tenant' => $parts[2] ?? null,
                'scope' => $parts[3] ?? $channel,
                'origin' => self::origin(),
            ]);
        }
    }

    /**
     * Who asked for the publish, in a word: an ORM write, or the first class
     * outside the transport that called. Development only — it walks the stack.
     */
    private static function origin(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            $class = $frame['class'] ?? null;
            if ($class === null) {
                continue;
            }
            if ($class === ResourceInvalidationPublisher::class) {
                return 'orm write';
            }
            if (str_starts_with($class, 'Semitexa\\Ssr\\Application\\Service\\Async\\')) {
                continue;
            }

            return substr($class, (int) strrpos($class, '\\') + 1);
        }

        return null;
    }
}
