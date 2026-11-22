<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Pipeline\Exception\AuthenticationRequiredException;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Handler\PayloadHandler\SsrFallbackHandler;
use Semitexa\Ssr\Application\Payload\Request\SsrFallbackPayload;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Application\Service\DeferredSlotAccessGate;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredBindCookie;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Application\Service\KissVisitor;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture\HugArrayContainer;
use Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture\HugListenerRegistry;
use Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture\HugPagePolicyListener;
use Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture\HugProtectedPagePayload;
use Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture\HugProtectedPageResource;
use Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture\HugPublicPagePayload;
use Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture\HugPublicPageResource;
use Semitexa\Ssr\Tests\Unit\Async\Fixture\TimedSlotProvider;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * tk-ssr-hug-deferred-slots-auth-bypass: GET /__semitexa_hug?handle=…&slots=…
 * is a public route and a slot handler sees no request, so the deferred slots
 * of a protected page were drawn for anyone who named its handle. The page's
 * own access policy is now asked before a slot is named or drawn — through the
 * real admission, with a probe listener standing for the policy — and it does
 * not depend on the deferred request, which a fallback long after the page
 * loaded no longer has.
 */
final class SsrFallbackSlotAccessTest extends TestCase
{
    private const PUBLIC_PAGE = 'hug_public_page';
    private const PROTECTED_PAGE = 'hug_protected_page';
    private const BIND_TOKEN = 'bind-token-of-the-page';
    /** What a request id reads as once its 120 seconds are over: the registry does not hold it. */
    private const EXPIRED = 'dr_expired_long_ago';
    private const OWNER = ['session' => 'owner'];

    private mixed $slots = [];

    private mixed $providers = [];

    private ?object $catalog = null;

    protected function setUp(): void
    {
        $this->slots = (new \ReflectionProperty(LayoutSlotRegistry::class, 'slots'))->getValue();
        $this->providers = (new \ReflectionProperty(DataProviderRegistry::class, 'providerMap'))->getValue();
        /** @var object|null $catalog */
        $catalog = (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->getValue();
        $this->catalog = $catalog;

        $fresh = new ModuleTemplateCatalog();
        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'twig'))
            ->setValue($fresh, new Environment(new ArrayLoader(['hug-access.twig' => '<b>{{ label }}</b>'])));
        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'initialized'))->setValue($fresh, true);
        ModuleTemplateRegistry::setCatalog($fresh);

        TimedSlotProvider::reset();
        foreach ([self::PUBLIC_PAGE => 'open_news', self::PROTECTED_PAGE => 'owner_orders'] as $page => $slot) {
            LayoutSlotRegistry::register(handle: $page, slot: $slot, template: 'hug-access.twig', priority: 1, deferred: true);
            DataProviderRegistry::register($slot, TimedSlotProvider::class);
        }

        HugPagePolicyListener::$asked = [];
        DeferredRequestRegistry::reset();
        DeferredRequestRegistry::initialize(new IsomorphicConfig(enabled: true, deferredContextSize: 8192));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(LayoutSlotRegistry::class, 'slots'))->setValue(null, $this->slots);
        (new \ReflectionProperty(DataProviderRegistry::class, 'providerMap'))->setValue(null, $this->providers);
        (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->setValue(null, $this->catalog);
        TimedSlotProvider::reset();
        DeferredRequestRegistry::reset();
    }

    #[Test]
    public function an_anonymous_request_for_a_protected_pages_slots_is_refused_as_the_page_refuses_it(): void
    {
        $pageRefusal = $this->refusalOfThePageItself();

        foreach (['' => 'no request id', self::EXPIRED => 'an expired request id'] as $requestId => $case) {
            try {
                $this->ask(self::PROTECTED_PAGE, 'owner_orders', $requestId);
                self::fail('the slot of a protected page was drawn for an anonymous request with ' . $case);
            } catch (AuthenticationRequiredException $refusal) {
                self::assertSame($pageRefusal->getMessage(), $refusal->getMessage(), $case);
                self::assertSame($pageRefusal->getStatusCode(), $refusal->getStatusCode(), $case);
            }
        }

        self::assertSame([], TimedSlotProvider::$ranIn, 'the slot\'s data was resolved before the request was refused');
        self::assertSame(
            [HugProtectedPagePayload::class, HugProtectedPagePayload::class],
            array_column(HugPagePolicyListener::$asked, 'payload'),
            'the policy asked was not the page\'s own',
        );
    }

    #[Test]
    public function a_refused_request_learns_nothing_about_the_pages_slot_names(): void
    {
        $this->expectException(AuthenticationRequiredException::class);

        // Before the fix order mattered too: an unknown slot answered 404 naming it.
        $this->ask(self::PROTECTED_PAGE, 'owner_orders,not_a_slot_of_this_page', '');
    }

    #[Test]
    public function the_signed_in_owner_still_gets_the_slot_with_and_without_a_live_request(): void
    {
        self::assertSame(
            ['owner_orders' => '<b>data of owner_orders</b>'],
            $this->ask(self::PROTECTED_PAGE, 'owner_orders', '', self::OWNER),
        );
        self::assertSame(
            ['owner_orders' => '<b>data of owner_orders</b>'],
            $this->ask(self::PROTECTED_PAGE, 'owner_orders', self::EXPIRED, self::OWNER),
            'the owner lost the fallback once the deferred request expired',
        );
    }

    #[Test]
    public function a_public_pages_slots_still_render_with_no_request_id_and_with_an_expired_one(): void
    {
        self::assertSame(['open_news' => '<b>data of open_news</b>'], $this->ask(self::PUBLIC_PAGE, 'open_news', ''));
        self::assertSame(
            ['open_news' => '<b>data of open_news</b>'],
            $this->ask(self::PUBLIC_PAGE, 'open_news', self::EXPIRED),
            'a public page lost the fallback once the deferred request expired',
        );
        self::assertSame([false, false], array_column(HugPagePolicyListener::$asked, 'reRun'), 'an unproven request passed as a page already served');
    }

    #[Test]
    public function the_bind_cookie_of_a_live_request_does_not_open_a_protected_page_to_a_signed_out_browser(): void
    {
        DeferredRequestRegistry::store('dr_live', self::PROTECTED_PAGE, [], [], self::BIND_TOKEN);
        $bound = [DeferredBindCookie::nameFor('dr_live') => self::BIND_TOKEN];

        self::assertSame(
            ['owner_orders' => '<b>data of owner_orders</b>'],
            $this->ask(self::PROTECTED_PAGE, 'owner_orders', 'dr_live', $bound + self::OWNER),
        );
        self::assertSame([true], array_column(HugPagePolicyListener::$asked, 'reRun'), 'a page already served to this browser was counted as a new request');

        // The same browser after signing out, inside the request's lifetime.
        $this->expectException(AuthenticationRequiredException::class);
        $this->ask(self::PROTECTED_PAGE, 'owner_orders', 'dr_live', $bound);
    }

    #[Test]
    public function a_live_request_of_another_page_is_refused(): void
    {
        DeferredRequestRegistry::store('dr_public', self::PUBLIC_PAGE, ['who' => 'the public page'], [], self::BIND_TOKEN);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('dr_public');

        $this->ask(self::PROTECTED_PAGE, 'owner_orders', 'dr_public', [DeferredBindCookie::nameFor('dr_public') => self::BIND_TOKEN] + self::OWNER);
    }

    #[Test]
    public function a_handle_no_route_answers_with_is_refused_unless_the_page_was_served_to_this_browser(): void
    {
        LayoutSlotRegistry::register(handle: 'hug_routeless_page', slot: 'routeless', template: 'hug-access.twig', priority: 1, deferred: true);
        DataProviderRegistry::register('routeless', TimedSlotProvider::class);
        DeferredRequestRegistry::store('dr_routeless', 'hug_routeless_page', [], [], self::BIND_TOKEN);

        self::assertSame(
            ['routeless' => '<b>data of routeless</b>'],
            $this->ask('hug_routeless_page', 'routeless', 'dr_routeless', [DeferredBindCookie::nameFor('dr_routeless') => self::BIND_TOKEN]),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('hug_routeless_page');
        $this->ask('hug_routeless_page', 'routeless', '');
    }

    #[Test]
    public function a_handle_shared_with_a_public_page_is_as_open_as_that_page(): void
    {
        $routes = $this->routes();
        $routes->register([
            'path' => '/hug/also-protected', 'methods' => ['GET'], 'name' => 'hug.also_protected',
            'class' => HugProtectedPagePayload::class, 'responseClass' => HugPublicPageResource::class,
            'accessType' => PayloadAccessType::Protected,
        ]);

        self::assertSame(['open_news' => '<b>data of open_news</b>'], $this->ask(self::PUBLIC_PAGE, 'open_news', '', [], $routes));
        self::assertSame([HugPublicPagePayload::class], array_column(HugPagePolicyListener::$asked, 'payload'), 'the public page was not asked first');
    }

    /** What the protected page's own AuthCheck answers an anonymous GET to it. */
    private function refusalOfThePageItself(): AuthenticationRequiredException
    {
        $route = $this->routes()->findRouteTyped('/hug/protected');
        self::assertInstanceOf(DiscoveredRoute::class, $route);
        try {
            (new HugPagePolicyListener())->handle(new RequestPipelineContext(
                requestDto: new HugProtectedPagePayload(),
                route: $route,
                request: $this->request('/hug/protected', []),
            ));
        } catch (AuthenticationRequiredException $refusal) {
            HugPagePolicyListener::$asked = [];

            return $refusal;
        }

        self::fail('the probe page is not protected: the test proves nothing');
    }

    /**
     * @param array<string, string> $cookies
     * @return array<string, string> slot id => rendered HTML
     */
    private function ask(string $handle, string $slots, string $requestId, array $cookies = [], ?RouteRegistry $routes = null): array
    {
        $payload = new SsrFallbackPayload();
        $payload->setHandle($handle);
        $payload->setSlots($slots);
        $payload->setDeferredRequestId($requestId);

        // The real admission: a bare payload, then the AuthCheck phase's listeners.
        $container = new HugArrayContainer([
            PayloadPartRegistry::class => new PayloadPartRegistry(),
            PipelineListenerRegistry::class => new HugListenerRegistry([HugPagePolicyListener::class]),
            HugPagePolicyListener::class => new HugPagePolicyListener(),
        ]);
        $gate = (new DeferredSlotAccessGate())->withCollaborators(
            $routes ?? $this->routes(),
            new AttributeDiscovery(new ClassDiscovery(), new ModuleRegistry(), new RouteRegistry()),
            null,
            $container,
        );

        $handler = new SsrFallbackHandler();
        (new \ReflectionProperty(SsrFallbackHandler::class, 'orchestrator'))->setValue($handler, $this->orchestrator());
        (new \ReflectionProperty(SsrFallbackHandler::class, 'access'))->setValue($handler, $gate);
        (new \ReflectionProperty(SsrFallbackHandler::class, 'request'))->setValue($handler, $this->request('/__semitexa_hug', $cookies));

        /** @var array<string, string> $rendered */
        $rendered = $handler->handle($payload, new ResourceResponse())->getContext();

        return $rendered;
    }

    private function routes(): RouteRegistry
    {
        $routes = new RouteRegistry();
        $routes->register([
            'path' => '/hug/protected', 'methods' => ['GET'], 'name' => 'hug.protected',
            'class' => HugProtectedPagePayload::class, 'responseClass' => HugProtectedPageResource::class,
            'accessType' => PayloadAccessType::Protected,
        ]);
        $routes->register([
            'path' => '/hug/public', 'methods' => ['GET'], 'name' => 'hug.public',
            'class' => HugPublicPagePayload::class, 'responseClass' => HugPublicPageResource::class,
            'accessType' => PayloadAccessType::Public,
        ]);

        return $routes;
    }

    /** @param array<string, string> $cookies */
    private function request(string $uri, array $cookies): Request
    {
        return new Request(method: 'GET', uri: $uri, headers: [], query: [], post: [], server: [], cookies: $cookies);
    }

    private function orchestrator(): DeferredBlockOrchestrator
    {
        $orchestrator = new DeferredBlockOrchestrator();
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'sseServer'))->setValue($orchestrator, AsyncResourceSseServer::instance());
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'dataProviderRegistry'))->setValue($orchestrator, new DataProviderRegistry());
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'logger'))->setValue($orchestrator, $this->createStub(LoggerInterface::class));
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'kissVisitor'))->setValue($orchestrator, new KissVisitor());

        return $orchestrator;
    }
}
