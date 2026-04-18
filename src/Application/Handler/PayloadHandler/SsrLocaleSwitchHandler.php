<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Payload\Request\SsrLocaleSwitchPayload;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;
use Swoole\Coroutine;

#[AsPayloadHandler(payload: SsrLocaleSwitchPayload::class, resource: ResourceResponse::class)]
final class SsrLocaleSwitchHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected DeferredBlockOrchestrator $orchestrator;

    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    public function handle(SsrLocaleSwitchPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $locale = trim($payload->getLocale());
        if ($locale === '') {
            throw new NotFoundException('Locale', '(empty)');
        }

        if (class_exists(\Semitexa\Locale\LocaleConfig::class)) {
            $config = \Semitexa\Locale\LocaleConfig::fromEnvironment();
            if (!in_array($locale, $config->supportedLocales, true)) {
                throw new NotFoundException('Locale', $locale);
            }
        }

        $sessionId = trim($payload->getSessionId());
        if ($sessionId === '') {
            throw new NotFoundException('SSE session', '(empty)');
        }
        if (!AsyncResourceSseServer::isSessionActive($sessionId)) {
            throw new NotFoundException('SSE session', $sessionId);
        }

        $requestId = trim($payload->getDeferredRequestId());
        if ($requestId === '') {
            throw new NotFoundException('Deferred request', '(empty)');
        }

        $entry = DeferredRequestRegistry::consume($requestId);
        if ($entry === null) {
            throw new NotFoundException('Deferred request', $requestId);
        }
        $bindToken = $this->request->getCookie('semitexa_ssr_bind', '');
        if ($bindToken === '' || !hash_equals($entry['bind_token'], $bindToken)) {
            throw new NotFoundException('Deferred request', $requestId);
        }

        $pageHandle = $entry['page_handle'];
        $pageContext = $entry['page_context'];

        if (class_exists(Coroutine::class, false) && Coroutine::getCid() > 0) {
            AsyncResourceSseServer::createSessionCoroutine(function () use ($sessionId, $pageHandle, $pageContext, $locale): void {
                try {
                    $this->orchestrator->streamDeferredBlocks(
                        sessionId: $sessionId,
                        pageHandle: $pageHandle,
                        pageContext: $pageContext,
                        lastEventId: null,
                        deferredRequestId: null,
                        locale: $locale,
                        startLiveLoop: false,
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('SSR locale switch failed', [
                        'locale' => $locale,
                        'session_id' => $sessionId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }, $sessionId);
        } else {
            $this->orchestrator->streamDeferredBlocks(
                sessionId: $sessionId,
                pageHandle: $pageHandle,
                pageContext: $pageContext,
                lastEventId: null,
                deferredRequestId: null,
                locale: $locale,
                startLiveLoop: false,
            );
        }

        $resource->setContent('');
        return $resource;
    }
}
