<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Integration\Isomorphic;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentCatalog;
use Semitexa\Ssr\Application\Service\Component\ComponentInstanceStore;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;
use Semitexa\Ssr\Context\IsomorphicContextStore;

/**
 * A guest's session is kept for a page that is sent a stream, and for no
 * other (idea-anonymous-error-responses-mint-session-cookies, review of
 * 957fbd9).
 *
 * Core stores a guest session only once the request uses it. The first ssr
 * change asked for the session id where the page's stream id is minted, and
 * that happens before rendering on every HTML page of an app that has a
 * deferred SSE component anywhere: an anonymous HTML 404 and a plain page
 * stored a session and set both cookies again, so the flood the core change
 * closes was open for any app with ssr installed.
 *
 * The whole seam, with nothing stubbed between: core's SessionPhase on the
 * real table store, the binding the pipeline listener makes, HtmlResponse
 * rendering through the real Twig environment, then the phase's finalize.
 */
final class OnlyAPageThatStreamsKeepsItsGuestSessionTest extends TestCase
{
    private const SLOTS = ['core.csrf_session', DefaultTenantContextStore::CONTEXT_KEY];

    /**
     * Process-global registries the fixture replaces, with the value each
     * starts from in the test. The ones already there are set aside, never
     * touched (their setters change the instance they hold), and put back,
     * the same instances, after the test.
     */
    private const STATICS = [
        [ComponentRegistry::class, 'catalog', null],
        [ModuleTemplateRegistry::class, 'catalog', null],
        [ModuleAssetRegistry::class, 'resolver', null],
        [TwigExtensionRegistry::class, 'catalog', null],
        [DeferredRequestRegistry::class, 'table', null],
        [DeferredRequestRegistry::class, 'gcTimerId', 0],
        [DeferredRequestRegistry::class, 'contextColumnSize', 0],
        [DeferredRequestRegistry::class, 'snapshotColumnSize', 0],
        [DeferredRequestRegistry::class, 'deliveredLock', null],
    ];

    /** @var list<array{\ReflectionProperty, mixed}> */
    private array $staticsBefore = [];

    /** @var array<string, string|false> */
    private array $envBefore = [];

    /** @var array<string, mixed> */
    private array $slotsBefore = [];

    private bool $templatesPublishedBefore = false;

    /** @var array{session: SessionInterface|string|null, t: string}|null */
    private ?array $bindingBefore = null;

    protected function setUp(): void
    {
        foreach (['REDIS_HOST', 'SSR_ISOMORPHIC_ENABLED'] as $name) {
            $this->envBefore[$name] = getenv($name);
        }
        putenv('REDIS_HOST=');
        putenv('SSR_ISOMORPHIC_ENABLED=true');
        $this->slotsBefore = [];
        foreach (self::slots() as $slot) {
            if (CoroutineLocal::has($slot)) {
                $this->slotsBefore[$slot] = CoroutineLocal::get($slot);
            }
        }
        $this->bindingBefore = SignedContextBinding::snapshot();
        $this->staticsBefore = [];
        foreach (self::STATICS as [$class, $name, $start]) {
            $property = new \ReflectionProperty($class, $name);
            $this->staticsBefore[] = [$property, $property->getValue()];
            $property->setValue(null, $start);
        }
        SeoMeta::reset();

        ModuleTemplateRegistry::setModuleRegistry(new ModuleRegistry());
        ModuleAssetRegistry::setModuleRegistry(new ModuleRegistry());
        TwigExtensionRegistry::setClassDiscovery(new ClassDiscovery());
        // An app that has a deferred SSE component somewhere: every HTML page
        // of it gets a stream id before it renders, whether it uses the
        // component or not.
        $catalog = new ComponentCatalog();
        (new \ReflectionClass($catalog))->getProperty('initialized')->setValue($catalog, true);
        ComponentRegistry::setCatalog($catalog);
        ComponentRegistry::register([
            'class' => 'DeferredDemoComponent',
            'name' => 'deferred_demo',
            'template' => 'components/deferred_demo.html.twig',
            'layout' => null,
            'cacheable' => true,
            'event' => null,
            'triggers' => [],
            'script' => null,
            'dataProviderClass' => null,
            'transportMode' => TransportType::Sse,
            'deferred' => true,
        ]);
        // Publishing deferred templates to public/ is not this test's subject.
        $published = new \ReflectionProperty(DeferredTemplateRegistry::class, 'initialized');
        $this->templatesPublishedBefore = (bool) $published->getValue();
        $published->setValue(null, true);
        $this->resetRenderState();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(DeferredTemplateRegistry::class, 'initialized'))->setValue(null, $this->templatesPublishedBefore);
        $this->resetRenderState();
        // Stops a GC timer the test's renders started; only the test's own
        // registry is in place at this point.
        DeferredRequestRegistry::reset();
        foreach ($this->staticsBefore as [$property, $value]) {
            $property->setValue(null, $value);
        }
        SignedContextBinding::restore($this->bindingBefore);
        foreach (self::slots() as $slot) {
            if (array_key_exists($slot, $this->slotsBefore)) {
                CoroutineLocal::set($slot, $this->slotsBefore[$slot]);
            } else {
                CoroutineLocal::remove($slot);
            }
        }
        foreach ($this->envBefore as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function pagesWithoutAStream(): iterable
    {
        yield 'an HTML 404' => [404, '<html><body><h1>Not found</h1></body></html>'];
        yield 'a page without deferred content' => [200, '<html><body><h1>{{ title }}</h1><p>Plain.</p></body></html>'];
    }

    #[Test]
    #[DataProvider('pagesWithoutAStream')]
    public function a_page_that_is_sent_no_stream_stores_no_session(int $status, string $template): void
    {
        $table = $this->table();

        [$html, $cookies] = $this->serveToAGuest($table, $status, $template);

        self::assertTrue(ComponentRegistry::hasDeferredSseComponent(), 'the app does have a deferred SSE component');
        self::assertStringContainsString('<h1>', $html, 'the page was rendered');
        self::assertStringNotContainsString('data-ssr-deferred-component', $html);
        self::assertSame(0, $table->count(), 'no deferred content on the page, no stream, no session');
        self::assertSame([], $cookies);
    }

    #[Test]
    public function a_page_that_renders_a_deferred_component_keeps_its_guest_session(): void
    {
        $table = $this->table();

        [$html, $cookies] = $this->serveToAGuest(
            $table,
            200,
            "<html><body><h1>Feed</h1>{{ component('deferred_demo', {title: 'Hello'}) }}</body></html>",
        );

        self::assertStringContainsString('data-ssr-deferred-component="deferred_demo"', $html, 'the page really defers: a placeholder, filled on the stream');
        self::assertArrayHasKey('semitexa_session', $cookies, 'the stream is opened with the session the page ran on');
        self::assertTrue($table->exists($cookies['semitexa_session']));
        self::assertSame(1, $table->count());
    }

    /** A live page asks for its stream itself: its handler or its template mints the stream id. */
    #[Test]
    public function a_page_that_asks_for_a_live_stream_keeps_its_guest_session(): void
    {
        $table = $this->table();

        [$html, $cookies] = $this->serveToAGuest(
            $table,
            200,
            '<html><body><h1>Live</h1><i>{{ stream }}</i></body></html>',
            static fn (): array => ['stream' => UiSseSessionState::mintIfAbsent()],
        );

        self::assertMatchesRegularExpression('~<i>sse_[0-9a-f]{32}</i>~', $html);
        self::assertArrayHasKey('semitexa_session', $cookies);
        self::assertSame(1, $table->count());
    }

    /**
     * One guest request: session phase, the pipeline's binding, the page, the end of the request.
     *
     * @param (\Closure(): array<string, mixed>)|null $handler the page's handler: what it adds to the context
     * @return array{string, array<string, string>} the HTML, and the cookies the response sets
     */
    private function serveToAGuest(\Swoole\Table $table, int $status, string $template, ?\Closure $handler = null): array
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $scope = new RequestScopedContainer($container);
        $phase = new SessionPhase($container, $scope, new DefaultTenantContextStore(), null);
        (new \ReflectionProperty(SessionPhase::class, 'sessionHandler'))->setValue($phase, new SwooleTableSessionHandler($table));
        $context = new RequestLifecycleContext(new Request('GET', '/', ['Accept' => 'text/html'], [], [], ['REQUEST_URI' => '/'], []));

        $phase->execute($context);
        $session = $scope->get(SessionInterface::class);
        self::assertInstanceOf(SessionInterface::class, $session);
        // What BindRequestToComponentRendererListener does for every routed request.
        SignedContextBinding::bind($session, '');

        $page = new class extends HtmlResponse {
        };
        $page->renderString($template, ['page_handle' => 'demo.page', 'title' => 'Home'] + ($handler !== null ? $handler() : []));

        $response = $phase->finalize($context, new HttpResponse($page->getContent(), $status));

        $cookies = [];
        foreach ((array) ($response->getHeaders()['Set-Cookie'] ?? []) as $line) {
            if (is_string($line) && preg_match('/^([^=]+)=([^;]*)/', $line, $m) === 1) {
                $cookies[$m[1]] = rawurldecode($m[2]);
            }
        }

        return [$response->getContent(), $cookies];
    }

    /**
     * The coroutine slots the fixture restores: the session and tenant ones,
     * and SeoMeta's, which a render fills (read from the class, so a renamed
     * key cannot slip past).
     *
     * @return list<string>
     */
    private static function slots(): array
    {
        $seo = [];
        foreach (['KEY_META', 'KEY_TITLE', 'KEY_TITLE_SUFFIX', 'KEY_TITLE_PREFIX'] as $constant) {
            $seo[] = (string) (new \ReflectionClassConstant(SeoMeta::class, $constant))->getValue();
        }

        return [...self::SLOTS, ...$seo];
    }

    private function resetRenderState(): void
    {
        IsomorphicContextStore::reset();
        ComponentInstanceStore::reset();
        UiSseSessionState::reset();
    }

    private function table(): \Swoole\Table
    {
        $table = new \Swoole\Table(64);
        $table->column('data', \Swoole\Table::TYPE_STRING, 1024);
        $table->column('expires_at', \Swoole\Table::TYPE_INT);
        $table->create();

        return $table;
    }
}
