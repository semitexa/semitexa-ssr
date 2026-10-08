<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Semitexa\Ssr\Application\Service\Async\SseServer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Runs the renders of a page's deferred component instances for one SSE
 * session and hands their answers back as they arrive.
 *
 * Each render runs in its own session coroutine, all at once, and is answered
 * as it finishes: a slow one does not hold up the others. Without coroutines
 * (CLI, a test) or with a single instance they run one after another, before
 * the first answer is handed back.
 *
 * Extracted from {@see DeferredBlockOrchestrator::streamComponentInstances()},
 * which keeps what is rendered and what is sent; this owns only how the renders
 * are run and waited for. A plain helper the orchestrator builds, not a
 * container service.
 *
 * @phpstan-type RenderAnswer array{0: ?string, 1: ?string, 2: ?string}
 */
final class DeferredComponentRenderFanOut
{
    /** How long the stream waits for one deferred component before it stops waiting. */
    public const TIMEOUT_SECONDS = 30.0;

    /** What a render that could not run answers: nothing to send, but an answer. */
    private const NO_ANSWER = [null, null, null];

    public function __construct(private readonly SseServer $sseServer)
    {
    }

    /**
     * The renders' answers — `[instanceId, name, html]` each, `html` null when
     * there is nothing to send — in the order they arrive. Stops when the
     * session goes away. A null means nothing answered within
     * {@see TIMEOUT_SECONDS}, and nothing more will be handed back.
     *
     * @param list<\Closure(): RenderAnswer> $renders
     * @return \Generator<int, RenderAnswer|null>
     */
    public function answers(string $sessionId, array $renders): \Generator
    {
        $concurrent = class_exists(Channel::class, false)
            && class_exists(Coroutine::class, false) && Coroutine::getCid() > 0 && count($renders) > 1;
        if (!$concurrent) {
            $answers = array_map(static fn (\Closure $render): array => $render(), $renders);
            foreach ($answers as $answer) {
                if (!$this->sseServer->isSessionActive($sessionId)) {
                    return;
                }
                yield $answer;
            }

            return;
        }

        $channel = new Channel(count($renders));
        foreach ($renders as $render) {
            $spawned = $this->sseServer->createSessionCoroutine(static function () use ($render, $channel): void {
                $answer = self::NO_ANSWER;
                try {
                    $answer = $render();
                } finally {
                    $channel->push($answer); // always: the loop below waits for one answer per render
                }
            }, $sessionId);
            if ($spawned === false) {
                $channel->push(self::NO_ANSWER);
            }
        }

        foreach ($renders as $_) {
            if (!$this->sseServer->isSessionActive($sessionId)) {
                return;
            }
            $answer = $channel->pop(self::TIMEOUT_SECONDS);
            if (!is_array($answer)) {
                yield null;

                return;
            }
            /** @var RenderAnswer $answer only answers and NO_ANSWER are pushed */
            yield $answer;
        }
    }
}
