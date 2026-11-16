<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Handler\PayloadHandler\SsrFallbackHandler;
use Semitexa\Ssr\Application\Payload\Request\SsrFallbackPayload;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredBindCookie;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;

/**
 * tk-sse-drain-graceful-handoff: GET /__semitexa_hug?components=… draws the deferred
 * components a stream left pending. A component renders from props its page
 * recorded, so the request is refused unless it comes from the browser that holds
 * that page's bind cookie, and names only instances that page recorded. The
 * rendering itself is proven in the browser (deferred-component-fallback.spec.ts).
 */
final class SsrFallbackComponentRequestTest extends TestCase
{
    private const REQUEST_ID = 'dr_components_fallback';
    private const BIND_TOKEN = 'bind-token-of-the-page';

    protected function setUp(): void
    {
        DeferredRequestRegistry::reset();
        DeferredRequestRegistry::initialize(new IsomorphicConfig(enabled: true, deferredContextSize: 8192));
        DeferredRequestRegistry::store(self::REQUEST_ID, 'probe.page', [], [], self::BIND_TOKEN);
        DeferredRequestRegistry::storeComponentInstances(self::REQUEST_ID, [
            ['instance_id' => 'cmp-1', 'name' => 'probe.card', 'props' => ['who' => 'recorded']],
        ]);
    }

    protected function tearDown(): void
    {
        DeferredRequestRegistry::reset();
    }

    #[Test]
    public function aRequestWithoutThePagesBindCookieIsRefused(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(self::REQUEST_ID);

        $this->ask('cmp-1', self::REQUEST_ID, []);
    }

    #[Test]
    public function aRequestWithAnotherBindTokenIsRefused(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(self::REQUEST_ID);

        $this->ask('cmp-1', self::REQUEST_ID, [DeferredBindCookie::nameFor(self::REQUEST_ID) => 'someone-else']);
    }

    #[Test]
    public function anInstanceThePageNeverRecordedIsRefusedByName(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('cmp-not-on-this-page');

        $this->ask('cmp-1,cmp-not-on-this-page', self::REQUEST_ID, [DeferredBindCookie::nameFor(self::REQUEST_ID) => self::BIND_TOKEN]);
    }

    #[Test]
    public function aRequestTheRegistryDoesNotHoldIsRefused(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('dr_gone');

        $this->ask('cmp-1', 'dr_gone', [DeferredBindCookie::nameFor('dr_gone') => self::BIND_TOKEN]);
    }

    #[Test]
    public function aComponentRequestWithoutARequestIdIsRefused(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('(empty)');

        $this->ask('cmp-1', '', []);
    }

    /** @param array<string, string> $cookies */
    private function ask(string $components, string $requestId, array $cookies): void
    {
        $payload = new SsrFallbackPayload();
        $payload->setComponents($components);
        $payload->setDeferredRequestId($requestId);

        $handler = new SsrFallbackHandler();
        (new \ReflectionProperty(SsrFallbackHandler::class, 'orchestrator'))
            ->setValue($handler, new DeferredBlockOrchestrator());
        (new \ReflectionProperty(SsrFallbackHandler::class, 'request'))->setValue($handler, new Request(
            method: 'GET',
            uri: '/__semitexa_hug',
            headers: [],
            query: [],
            post: [],
            server: [],
            cookies: $cookies,
        ));

        $handler->handle($payload, new ResourceResponse());
    }
}
