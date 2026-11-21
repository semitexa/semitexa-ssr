<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\GuestAuthContext;
use Semitexa\Core\Container\ExecutionContext;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Support\Row;
use Semitexa\Locale\Context\LocaleContextStore;
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
use Semitexa\Ssr\Tests\Unit\Async\Fixture\TimedSlotProvider;
use Swoole\Coroutine;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

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
        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'twig'))->setValue($fresh, new Environment(new ArrayLoader(['hug-probe.twig' => '<b>{{ label }}</b>'])));
        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'initialized'))->setValue($fresh, true);
        ModuleTemplateRegistry::setCatalog($fresh);

        // The stream's coroutines carry this container's context, as a worker's do.
        $this->container = new SemitexaContainer();
        (new \ReflectionProperty(SseServer::class, 'sessionCoroutines'))->setValue(AsyncResourceSseServer::instance(), new SseSessionCoroutines($this->container));
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

        try {
            $this->renderInCoroutine(static function () use ($container, $auth, &$parent, &$localeBefore): void {
                $parent = Coroutine::getCid();
                $localeBefore = LocaleContextStore::getLocale();
                // What the route pipeline has established by the time the handler runs.
                $container->setExecutionContext(new ExecutionContext(authContext: $auth));
                LocaleContextStore::setLocale('uk');
            });
        } finally {
            LocaleContextStore::clearFallback();
        }

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
     * @param (\Closure(): void)|null $before run in the request's coroutine, ahead of the render
     * @return array{array<string, string>, float} what was rendered, and how long it took, ms
     */
    private function renderInCoroutine(?\Closure $before = null): array
    {
        $orchestrator = $this->orchestrator();
        $rendered = [];
        $ms = 0.0;
        Coroutine\run(static function () use ($orchestrator, $before, &$rendered, &$ms): void {
            if ($before !== null) {
                $before();
            }
            $startedAt = hrtime(true);
            $rendered = $orchestrator->renderDeferredBlocksSync(self::PAGE, []);
            $ms = (hrtime(true) - $startedAt) / 1_000_000;
        });

        return [$rendered, $ms];
    }

    /** @param array<string, int> $waits slot id => how long its data takes, ms; in the page's slot order */
    private function probeSlots(array $waits): void
    {
        $priority = 0;
        foreach ($waits as $slot => $ms) {
            LayoutSlotRegistry::register(handle: self::PAGE, slot: $slot, template: 'hug-probe.twig', priority: ++$priority, deferred: true);
            DataProviderRegistry::register($slot, TimedSlotProvider::class);
        }
        TimedSlotProvider::$waits = $waits;
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
