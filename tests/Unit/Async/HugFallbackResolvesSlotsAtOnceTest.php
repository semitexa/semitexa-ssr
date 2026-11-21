<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\GuestAuthContext;
use Semitexa\Core\Container\ExecutionContext;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Server\AfterResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Support\Row;
use Semitexa\Core\Tenant\DefaultTenantContext;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Application\Service\DeferredSlotDataFanOut;
use Semitexa\Ssr\Application\Service\KissVisitor;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;
use Semitexa\Ssr\Tests\Unit\Async\Fixture\TimedSlotProvider;
use Swoole\Coroutine;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * tk-hug-fallback-renders-slots-in-sequence. A page whose stream is refused
 * gets its deferred slots through `GET /__semitexa_hug?slots=…`, and they were
 * resolved one after another there: slots of 300, 600 and 900 ms answered in
 * their sum (p90 1834 ms on the stand) while the stream it replaces answers in
 * the slowest. Their data now resolves at once, each in a coroutine carrying
 * the request's context, and Twig renders in the slots' order afterwards.
 *
 * What the one-after-another path gave is pinned beside it: the order, a
 * failing slot leaving the others alone, and that path itself where there is
 * no coroutine or no room for more of them.
 */
#[RequiresPhpExtension('swoole')]
final class HugFallbackResolvesSlotsAtOnceTest extends TestCase
{
    private const PAGE = 'hug_probe_page';

    private mixed $slots = [];

    private mixed $providers = [];

    private ?object $catalog = null;

    private mixed $sessionCoroutines = null;

    private SemitexaContainer $container;

    private SseSessionCoroutines $tracker;

    protected function setUp(): void
    {
        $this->slots = (new \ReflectionProperty(LayoutSlotRegistry::class, 'slots'))->getValue();
        $this->providers = (new \ReflectionProperty(DataProviderRegistry::class, 'providerMap'))->getValue();
        /** @var object|null $catalog */
        $catalog = (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->getValue();
        $this->catalog = $catalog;
        $this->sessionCoroutines = (new \ReflectionProperty(SseServer::class, 'sessionCoroutines'))->getValue(AsyncResourceSseServer::instance());

        // A catalog of its own, with one template every probe slot renders.
        $fresh = new ModuleTemplateCatalog();
        $twig = new Environment(new ArrayLoader([
            'hug-probe.twig' => '<b>{{ label }}</b>',
            // What the request's own coroutine holds when a slot's template renders.
            'hug-state.twig' => '{{ probe_ui_session() }}|{{ probe_inline_css() }}',
        ]));
        $twig->addFunction(new TwigFunction('probe_ui_session', static fn (): string => UiSseSessionState::current() ?? 'none'));
        $twig->addFunction(new TwigFunction('probe_inline_css', static fn (): string => implode(',', array_column(AssetCollectorStore::get()->takeRawInlineCss(), 'key'))));
        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'twig'))->setValue($fresh, $twig);
        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'initialized'))->setValue($fresh, true);
        ModuleTemplateRegistry::setCatalog($fresh);

        // The stream's coroutines carry this container's context, as a worker's do.
        $this->container = new SemitexaContainer();
        $this->tracker = new SseSessionCoroutines($this->container);
        (new \ReflectionProperty(SseServer::class, 'sessionCoroutines'))->setValue(AsyncResourceSseServer::instance(), $this->tracker);
        TimedSlotProvider::reset();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(LayoutSlotRegistry::class, 'slots'))->setValue(null, $this->slots);
        (new \ReflectionProperty(DataProviderRegistry::class, 'providerMap'))->setValue(null, $this->providers);
        (new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog'))->setValue(null, $this->catalog);
        (new \ReflectionProperty(SseServer::class, 'sessionCoroutines'))->setValue(AsyncResourceSseServer::instance(), $this->sessionCoroutines);
        TimedSlotProvider::reset();
    }

    #[Test]
    public function three_slots_cost_the_slowest_of_them_not_their_sum(): void
    {
        // The slowest first: an answer in the order they finish would be c, b, a.
        $this->probeSlots(['a_slow' => 900, 'b_quick' => 300, 'c_middle' => 600]);

        [$rendered, $ms] = $this->renderInCoroutine();

        self::assertSame(
            ['a_slow' => '<b>data of a_slow</b>', 'b_quick' => '<b>data of b_quick</b>', 'c_middle' => '<b>data of c_middle</b>'],
            $rendered,
            'the slots did not come back whole, in their own order',
        );
        self::assertGreaterThanOrEqual(880.0, $ms, 'answered before its slowest slot had its data: the probe measures nothing');
        self::assertLessThan(1250.0, $ms, 'three slots of 300/600/900 ms took their sum (~1800 ms), not the slowest (~900 ms)');
    }

    #[Test]
    public function a_failing_slot_leaves_the_others_as_they_are(): void
    {
        $this->probeSlots(['a_first' => 100, 'b_broken' => 100, 'c_last' => 100]);
        TimedSlotProvider::$failing = ['b_broken'];
        $parent = null;

        [$rendered] = $this->renderInCoroutine(static function () use (&$parent): void {
            $parent = Coroutine::getCid();
        });

        self::assertSame(['a_first' => '<b>data of a_first</b>', 'c_last' => '<b>data of c_last</b>'], $rendered);
        self::assertCount(3, array_unique(TimedSlotProvider::$ranIn), 'the slots did not resolve each in a coroutine of its own');
        self::assertNotContains($parent, TimedSlotProvider::$ranIn, 'a slot resolved in the request\'s own coroutine: one after another');
    }

    #[Test]
    public function the_request_context_is_there_inside_a_slots_data_provider(): void
    {
        $this->probeSlots(['a_one' => 50, 'b_two' => 50]);
        $container = $this->container;
        $auth = GuestAuthContext::getInstance();
        TimedSlotProvider::$look = static fn (): array => [
            'auth' => $container->captureExecutionContext()->authContext,
            'locale' => LocaleContextStore::getLocale(),
        ];
        $parent = null;
        $localeBefore = null;

        // Set inside the request's coroutine: it goes with it, and the process keeps the locale it had.
        $this->renderInCoroutine(static function () use ($container, $auth, &$parent, &$localeBefore): void {
            $parent = Coroutine::getCid();
            $localeBefore = LocaleContextStore::getLocale();
            // What the route pipeline has established by the time the handler runs.
            $container->setExecutionContext(new ExecutionContext(authContext: $auth));
            LocaleContextStore::setLocale('uk');
        });

        self::assertNotSame('', $localeBefore, 'no locale was read before the probe one was set');
        self::assertNotSame('uk', $localeBefore, 'the probe locale is the default one: seeing it proves nothing');
        self::assertCount(2, TimedSlotProvider::$ranIn, 'not every slot resolved');
        self::assertNotContains($parent, TimedSlotProvider::$ranIn, 'a slot resolved in the request\'s own coroutine: the context was never carried');
        foreach (['a_one', 'b_two'] as $slot) {
            self::assertSame($auth, TimedSlotProvider::$saw[$slot]['auth'] ?? null, $slot . ' resolved without the request\'s execution context');
            self::assertSame('uk', TimedSlotProvider::$saw[$slot]['locale'] ?? null, $slot . ' resolved outside the request\'s locale');
        }
    }

    #[Test]
    public function no_more_than_eight_slots_resolve_at_once_for_one_request(): void
    {
        $slots = [];
        foreach (range(10, 21) as $n) {
            $slots['slot_' . $n] = 100;
        }
        $this->probeSlots($slots);

        [$rendered, $ms] = $this->renderInCoroutine();

        self::assertSame(array_keys($slots), array_keys($rendered));
        self::assertSame(DeferredSlotDataFanOut::MAX_AT_ONCE, TimedSlotProvider::$peak, 'twelve slots did not resolve eight at a time');
        self::assertSame(8, DeferredSlotDataFanOut::MAX_AT_ONCE);
        self::assertLessThan(450.0, $ms, 'twelve slots of 100 ms, eight at once, are two rounds (~200 ms), not twelve');
    }

    /**
     * Past max_coroutine a Coroutine::create() is refused, and once a coroutine
     * HTTP server has run in the process the refusal is a segfault. So the
     * limit is asked first and never reached: with too little room left the
     * slots resolve in the request's own coroutine, one after another.
     */
    #[Test]
    public function with_no_room_for_more_coroutines_the_slots_resolve_one_after_another(): void
    {
        $this->probeSlots(['a_one' => 60, 'b_two' => 60, 'c_three' => 60]);
        $options = Coroutine::getOptions();
        $limitBefore = Row::asInt(is_array($options) ? ($options['max_coroutine'] ?? 0) : 0);
        $parent = null;
        $fitsWithRoom = null;
        $fitsWithout = null;

        try {
            [$rendered, $ms] = $this->renderInCoroutine(static function () use (&$parent, &$fitsWithRoom, &$fitsWithout): void {
                $parent = Coroutine::getCid();
                $fitsWithRoom = DeferredSlotDataFanOut::fits(3);
                // Room for the three, but not for what must stay free beside them.
                $stats = Coroutine::stats();
                Coroutine::set(['max_coroutine' => Row::asInt(is_array($stats) ? ($stats['coroutine_num'] ?? 0) : 0) + 3 + DeferredSlotDataFanOut::HEADROOM - 1]);
                $fitsWithout = DeferredSlotDataFanOut::fits(3);
            });
        } finally {
            Coroutine::set(['max_coroutine' => $limitBefore > 0 ? $limitBefore : 100000]);
        }

        self::assertTrue($fitsWithRoom, 'three slots did not fit under the default limit: the fallback below proves nothing');
        self::assertFalse($fitsWithout);
        self::assertSame(['a_one', 'b_two', 'c_three'], array_keys($rendered));
        self::assertSame(['a_one' => $parent, 'b_two' => $parent, 'c_three' => $parent], TimedSlotProvider::$ranIn, 'a coroutine was created with no room left for it');
        self::assertGreaterThanOrEqual(170.0, $ms, 'three slots of 60 ms one after another take their sum');
    }

    #[Test]
    public function outside_a_coroutine_the_slots_resolve_one_after_another(): void
    {
        $this->probeSlots(['a_one' => 10, 'b_broken' => 10, 'c_three' => 10]);
        TimedSlotProvider::$failing = ['b_broken'];

        self::assertFalse(DeferredSlotDataFanOut::fits(3));
        $rendered = $this->orchestrator()->renderDeferredBlocksSync(self::PAGE, []);

        self::assertSame(['a_one' => '<b>data of a_one</b>', 'c_three' => '<b>data of c_three</b>'], $rendered);
        self::assertSame(1, TimedSlotProvider::$peak);
    }

    #[Test]
    public function only_the_slots_asked_for_are_resolved(): void
    {
        $this->probeSlots(['a_one' => 20, 'b_two' => 20, 'c_three' => 20]);

        $rendered = [];
        $orchestrator = $this->orchestrator();
        Coroutine\run(static function () use ($orchestrator, &$rendered): void {
            $rendered = $orchestrator->renderDeferredBlocksSync(self::PAGE, ['c_three', 'a_one']);
        });

        self::assertSame(['a_one', 'c_three'], array_keys($rendered));
        self::assertEqualsCanonicalizing(['a_one', 'c_three'], array_keys(TimedSlotProvider::$ranIn), 'a slot nobody asked for was resolved, or one asked for was not');
    }

    /**
     * The route pipeline leaves more in the request's coroutine than the
     * carried stores name: the request itself (PreHydrationAuthGate), the
     * tenant of core's own store, the page's UI SSE session. A policy reading
     * "no request" inside a slot decided as for none, and failed open.
     */
    #[Test]
    public function each_slot_resolves_with_the_requests_own_request_tenant_and_ui_session(): void
    {
        $this->probeSlots(['a_one' => 50, 'b_two' => 50]);
        $request = new Request('GET', '/__semitexa_hug', [], ['slots' => 'a_one,b_two'], [], [], []);
        $tenant = new DefaultTenantContext();
        TimedSlotProvider::$look = static fn (): array => [
            'request' => CurrentRequestStore::get(),
            'tenant' => (new DefaultTenantContextStore())->tryGet(),
            'ui_session' => UiSseSessionState::current(),
        ];
        $tracker = $this->tracker;
        $parent = null;
        $coroutinesBefore = null;
        /** @var array<string, mixed> $left */
        $left = [];

        $this->renderInCoroutine(
            static function () use ($request, $tenant, &$parent, &$coroutinesBefore): void {
                $parent = Coroutine::getCid();
                CurrentRequestStore::set($request);
                (new DefaultTenantContextStore())->set($tenant);
                UiSseSessionState::setForTesting('sse_probe_page');
                $coroutinesBefore = self::coroutinesRunning();
            },
            static function () use ($tracker, &$parent, &$left): void {
                // The last resolving coroutine wakes this one from inside its own ending: let it end.
                Coroutine::sleep(0.01);
                $left = [
                    'request' => CurrentRequestStore::get(),
                    'tenant' => (new DefaultTenantContextStore())->tryGet(),
                    'ui_session' => UiSseSessionState::current(),
                    'coroutines' => self::coroutinesRunning(),
                    'tracked' => $tracker->hasAny('hug-slots-' . Row::asInt($parent)),
                ];
            },
        );

        self::assertCount(2, TimedSlotProvider::$ranIn, 'not every slot resolved');
        self::assertNotContains($parent, TimedSlotProvider::$ranIn, 'a slot resolved in the request\'s own coroutine: nothing had to be handed over');
        foreach (['a_one', 'b_two'] as $slot) {
            $saw = TimedSlotProvider::$saw[$slot] ?? [];
            self::assertSame($request, $saw['request'] ?? null, $slot . ' resolved with no request, or another one');
            self::assertSame($tenant, $saw['tenant'] ?? null, $slot . ' resolved outside the request\'s tenant');
            self::assertSame('sse_probe_page', $saw['ui_session'] ?? null, $slot . ' resolved outside the page\'s UI SSE session');
        }
        // And the request's own coroutine is as it was, with nothing still running for it.
        self::assertSame($request, $left['request'] ?? null);
        self::assertSame($tenant, $left['tenant'] ?? null);
        self::assertSame('sse_probe_page', $left['ui_session'] ?? null);
        self::assertGreaterThan(0, $coroutinesBefore, 'no coroutine was counted before the render: the count after proves nothing');
        self::assertSame($coroutinesBefore, $left['coroutines'] ?? null, 'a slot\'s coroutine outlived the answer');
        self::assertFalse($left['tracked'] ?? null, 'a slot\'s coroutine is still tracked after the answer');
    }

    /**
     * What a provider writes for the request to read afterwards must land in
     * the request's own: a slot's assets were required in a collector that
     * went with its coroutine, and a UI SSE session a provider minted stayed
     * there too — each provider minted its own and the template saw none.
     */
    #[Test]
    public function what_a_provider_requires_and_the_session_it_mints_are_there_when_the_templates_render(): void
    {
        $this->probeSlots(['a_one' => 30, 'b_two' => 60], template: 'hug-state.twig');
        TimedSlotProvider::$look = static function (): array {
            AssetCollectorStore::get()->inlineCss('probe:' . SseSessionCoroutines::currentCid(), '.probe{}');

            return ['minted' => UiSseSessionState::mintIfAbsent()];
        };
        $parent = null;
        $sessionBefore = 'unread';

        [$rendered] = $this->renderInCoroutine(static function () use (&$parent, &$sessionBefore): void {
            $parent = Coroutine::getCid();
            $sessionBefore = UiSseSessionState::current();
        });

        self::assertNull($sessionBefore, 'the request had a UI SSE session before any provider ran: minting proves nothing');
        self::assertCount(2, array_unique(TimedSlotProvider::$ranIn), 'the slots did not resolve each in a coroutine of its own');
        self::assertNotContains($parent, TimedSlotProvider::$ranIn);
        $minted = Row::asString(TimedSlotProvider::$saw['a_one']['minted'] ?? '');
        self::assertStringStartsWith('sse_', $minted);
        self::assertSame($minted, TimedSlotProvider::$saw['b_two']['minted'] ?? null, 'two providers of one request minted a UI SSE session each');
        // The first template takes what both slots asked for; the second finds the session still there.
        $css = 'probe:' . TimedSlotProvider::$ranIn['a_one'] . ',probe:' . TimedSlotProvider::$ranIn['b_two'];
        self::assertSame(['a_one' => $minted . '|' . $css, 'b_two' => $minted . '|'], $rendered);
    }

    /** The CSRF token is read through the session the route pipeline bound to the request's coroutine. */
    #[Test]
    public function a_provider_reads_the_requests_csrf_token(): void
    {
        $this->probeSlots(['a_one' => 20, 'b_two' => 20]);
        $token = new CsrfToken();
        $token->setValue('token-of-the-request');
        $session = $this->createStub(SessionInterface::class);
        $session->method('getPayload')->willReturn($token);
        $container = $this->container;
        TimedSlotProvider::$look = static fn (): array => ['csrf' => CsrfField::token()];
        $parent = null;

        $this->renderInCoroutine(static function () use ($container, $session, &$parent): void {
            $parent = Coroutine::getCid();
            $container->setExecutionContext(new ExecutionContext(session: $session));
        });

        self::assertCount(2, TimedSlotProvider::$ranIn, 'not every slot resolved');
        self::assertNotContains($parent, TimedSlotProvider::$ranIn);
        self::assertSame('token-of-the-request', TimedSlotProvider::$saw['a_one']['csrf'] ?? null);
        self::assertSame('token-of-the-request', TimedSlotProvider::$saw['b_two']['csrf'] ?? null);
    }

    /**
     * Work a provider leaves for after the response is not lost in a coroutine
     * that is not the request's: AfterResponse runs it there at once, as it
     * does in any such coroutine. (Holding it until the response is out would
     * need a way to hand it to the request's queue, which core does not give.)
     */
    #[Test]
    public function work_a_provider_schedules_for_after_the_response_is_run(): void
    {
        $this->probeSlots(['a_one' => 20, 'b_two' => 20]);
        /** @var list<int> $ranIn */
        $ranIn = [];
        TimedSlotProvider::$look = static function () use (&$ranIn): array {
            AfterResponse::schedule(static function () use (&$ranIn): void {
                $ranIn[] = Row::asInt(Coroutine::getCid());
            });

            return [];
        };

        [$rendered] = $this->renderInCoroutine();

        self::assertSame(['a_one', 'b_two'], array_keys($rendered));
        self::assertSame(array_values(TimedSlotProvider::$ranIn), $ranIn, 'each provider\'s work runs once, in the coroutine that scheduled it');
    }

    /**
     * The cap is per request, so two such requests on one worker can want more
     * database connections (16) than the pool holds (DB_POOL_SIZE 10). A query
     * that finds the pool empty waits for a connection to come back
     * (ConnectionPool::pop()), so the slots past the pool's size resolve later,
     * not never. Pinned with a pool that waits the same way.
     */
    #[Test]
    public function two_requests_wanting_more_connections_than_the_pool_holds_lose_no_slot(): void
    {
        $first = [];
        $second = [];
        foreach (range(1, 8) as $n) {
            $first['one_' . $n] = 0;
            $second['two_' . $n] = 0;
        }
        $this->probeSlots($first, 'hug_probe_one');
        $this->probeSlots($second, 'hug_probe_two');
        $orchestrator = $this->orchestrator();
        /** @var array<string, array<string, string>> $rendered */
        $rendered = [];
        $lent = 0;
        $mostLent = 0;
        $waited = 0;

        Coroutine\run(static function () use ($orchestrator, &$rendered, &$lent, &$mostLent, &$waited): void {
            $pool = new Coroutine\Channel(10);
            foreach (range(1, 10) as $connection) {
                $pool->push($connection);
            }
            TimedSlotProvider::$look = static function () use ($pool, &$lent, &$mostLent, &$waited): array {
                $waited += $pool->isEmpty() ? 1 : 0;
                $connection = $pool->pop(5.0);
                if ($connection === false) {
                    throw new \RuntimeException('no connection came back in time');
                }
                $mostLent = max($mostLent, ++$lent);
                Coroutine::sleep(0.05); // the query
                $lent--;
                $pool->push($connection);

                return [];
            };
            $done = new Coroutine\Channel(2);
            foreach (['hug_probe_one', 'hug_probe_two'] as $page) {
                Coroutine::create(static function () use ($orchestrator, $page, $done, &$rendered): void {
                    $rendered[$page] = $orchestrator->renderDeferredBlocksSync($page, []);
                    $done->push(true);
                });
            }
            $done->pop(10.0);
            $done->pop(10.0);
        });

        self::assertSame(array_keys($first), array_keys($rendered['hug_probe_one'] ?? []), 'a slot of the first request was dropped');
        self::assertSame(array_keys($second), array_keys($rendered['hug_probe_two'] ?? []), 'a slot of the second request was dropped');
        self::assertSame(10, $mostLent, 'the pool was never empty: the probe proves nothing');
        self::assertSame(6, $waited, 'sixteen slots over ten connections: six wait for one to come back');
    }

    /** The server's own limit is set on the server, where Coroutine::getOptions() does not show it. */
    #[Test]
    public function the_limit_of_the_environment_counts_when_swoole_names_none(): void
    {
        $before = getenv('SWOOLE_MAX_COROUTINE');
        $fitsUnder = null;
        $fitsAt = null;

        try {
            Coroutine\run(static function () use (&$fitsUnder, &$fitsAt): void {
                $running = self::coroutinesRunning();
                putenv('SWOOLE_MAX_COROUTINE=' . ($running + 3 + DeferredSlotDataFanOut::HEADROOM - 1));
                $fitsUnder = DeferredSlotDataFanOut::fits(3);
                putenv('SWOOLE_MAX_COROUTINE=' . ($running + 3 + DeferredSlotDataFanOut::HEADROOM));
                $fitsAt = DeferredSlotDataFanOut::fits(3);
            });
        } finally {
            putenv($before === false ? 'SWOOLE_MAX_COROUTINE' : 'SWOOLE_MAX_COROUTINE=' . $before);
        }

        self::assertFalse($fitsUnder, 'one coroutine short of the room the slots need, and they were still spread out');
        self::assertTrue($fitsAt);
    }

    private static function coroutinesRunning(): int
    {
        $stats = Coroutine::stats();

        return Row::asInt(is_array($stats) ? ($stats['coroutine_num'] ?? 0) : 0);
    }

    /**
     * @param (\Closure(): void)|null $before run in the request's coroutine, ahead of the render
     * @param (\Closure(): void)|null $after run there too, once the render has answered
     * @return array{array<string, string>, float} what was rendered, and how long it took, ms
     */
    private function renderInCoroutine(?\Closure $before = null, ?\Closure $after = null): array
    {
        $orchestrator = $this->orchestrator();
        $rendered = [];
        $ms = 0.0;
        Coroutine\run(static function () use ($orchestrator, $before, $after, &$rendered, &$ms): void {
            if ($before !== null) {
                $before();
            }
            $startedAt = hrtime(true);
            $rendered = $orchestrator->renderDeferredBlocksSync(self::PAGE, []);
            $ms = (hrtime(true) - $startedAt) / 1_000_000;
            if ($after !== null) {
                $after();
            }
        });

        return [$rendered, $ms];
    }

    /** @param array<string, int> $waits slot id => how long its data takes, ms; in the page's slot order */
    private function probeSlots(array $waits, string $page = self::PAGE, string $template = 'hug-probe.twig'): void
    {
        $priority = 0;
        foreach ($waits as $slot => $ms) {
            LayoutSlotRegistry::register(handle: $page, slot: $slot, template: $template, priority: ++$priority, deferred: true);
            DataProviderRegistry::register($slot, TimedSlotProvider::class);
        }
        TimedSlotProvider::$waits += $waits;
    }

    private function orchestrator(): DeferredBlockOrchestrator
    {
        $orchestrator = new DeferredBlockOrchestrator();
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'sseServer'))->setValue($orchestrator, AsyncResourceSseServer::instance());
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'dataProviderRegistry'))->setValue($orchestrator, new DataProviderRegistry());
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'logger'))->setValue($orchestrator, $this->createStub(LoggerInterface::class));
        $visitor = new KissVisitor();
        (new \ReflectionProperty(KissVisitor::class, 'container'))->setValue($visitor, $this->container);
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'kissVisitor'))->setValue($orchestrator, $visitor);

        return $orchestrator;
    }
}
