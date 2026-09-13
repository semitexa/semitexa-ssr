<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Ssr\Application\Service\Async\PipelineSubscriptionFactory;
use Semitexa\Ssr\Domain\Model\SubscriptionAttachment;

/**
 * The regression the factory change nearly shipped, pinned at the level it
 * would actually have happened: `build()`.
 *
 * SubscriptionPayloadConstructionTest proves the PHP semantics — a generated
 * wrapper inherits no CLASS attributes — with an anonymous subclass. That is
 * the mechanism, not the consequence. This exercises the real path: a route
 * whose request class declares #[WatchScopes], an #[InjectAsReadonly] property
 * and a payload PART, built through the factory.
 *
 * If the factory ever reads `$dto::class` again, the payload comes back as a
 * generated wrapper, `watchScopesOf()` sees no attribute, `scopeKeys` is empty
 * and the feed silently stops receiving invalidations. Nothing throws, which is
 * exactly why it needs a test rather than a comment.
 */
final class SubscriptionFactoryScopeKeysTest extends TestCase
{
    #[Test]
    public function build_reads_the_scopes_off_the_class_the_route_names(): void
    {
        $attachment = $this->build();

        self::assertNotNull($attachment, 'the stub route must resolve, or this proves nothing');
        self::assertSame(
            ['probe_rows', 'probe_counts'],
            $attachment->record->scopeKeys,
            'reading $dto::class here yields [] and the feed goes silent',
        );
    }

    /**
     * The precondition that makes the assertion above meaningful: the payload
     * really is a generated wrapper, so `$dto::class` is NOT the declared class.
     */
    #[Test]
    public function the_built_payload_is_a_generated_wrapper_not_the_declared_class(): void
    {
        $attachment = $this->build();
        self::assertNotNull($attachment);

        $dto = $attachment->context->getCachedDto();

        self::assertInstanceOf(ScopedFeedRequestProbe::class, $dto);
        self::assertNotSame(
            ScopedFeedRequestProbe::class,
            $dto::class,
            'without a part applied the two classes coincide and the bug is invisible',
        );
        self::assertCount(
            0,
            (new \ReflectionClass($dto))->getAttributes(WatchScopes::class),
            'the wrapper carries none of it — the whole reason the declared class must be read',
        );
    }

    /** And the payload got its injected dependency, which `new $dtoClass()` never did. */
    #[Test]
    public function the_built_payload_has_its_injected_dependency(): void
    {
        $attachment = $this->build();
        self::assertNotNull($attachment);

        $property = new \ReflectionProperty(ScopedFeedRequestProbe::class, 'collaborator');

        self::assertTrue($property->isInitialized($attachment->context->getCachedDto()));
    }

    /**
     * The seam without a container must not fatal.
     *
     * withDiscovery() predates the container property, so a caller that used
     * the two-argument form was left with an UNINITIALIZED typed property —
     * and build() then died on `$this->container->has(...)` with
     * "must not be accessed before initialization", nowhere near the seam that
     * caused it. Injection is skipped in that case, which is the seam's limit;
     * throwing is not.
     */
    #[Test]
    public function the_two_argument_seam_still_builds_without_a_container(): void
    {
        $attachment = $this->build(withContainer: false);

        self::assertNotNull($attachment);
        self::assertSame(['probe_rows', 'probe_counts'], $attachment->record->scopeKeys);
    }

    private function build(bool $withContainer = true): ?SubscriptionAttachment
    {
        $container = ContainerFactory::get();

        // Registered where the factory actually LOOKS. payloadPartRegistry()
        // prefers the container's registry over discovery's — the same order
        // RouteExecutor uses — so a part registered only on the stub below
        // would be ignored and the payload would come back unwrapped, quietly
        // removing the very precondition this test rests on.
        $parts = $container->has(PayloadPartRegistry::class)
            ? $container->get(PayloadPartRegistry::class)
            : new PayloadPartRegistry();
        self::assertInstanceOf(PayloadPartRegistry::class, $parts);
        $parts->registerPayloadPart(ScopedFeedRequestProbe::class, ScopedFeedRequestPartProbe::class);

        $discovery = new class ($parts) extends AttributeDiscovery {
            public function __construct(private PayloadPartRegistry $parts)
            {
            }

            public function getHandlerRegistry(): HandlerRegistry
            {
                return new HandlerRegistry();
            }

            public function getPayloadPartRegistry(): PayloadPartRegistry
            {
                return $this->parts;
            }
        };

        $routes = new class () extends RouteRegistry {
            public function find(string $path, string $method = 'GET'): ?array
            {
                return [
                    'path' => '/probe/feed',
                    'method' => 'GET',
                    'class' => ScopedFeedRequestProbe::class,
                    'type' => 'http-request',
                ];
            }
        };

        return (new PipelineSubscriptionFactory())
            ->withDiscovery($routes, $discovery, $withContainer ? $container : null)
            ->build(
                sessionId: 'sess-probe',
                streamingId: 'stream-probe',
                routePath: '/probe/feed',
                routeMethod: 'GET',
                requestSnapshot: [],
            );
    }
}

/** A feed request shaped like a real one: declared scopes plus an injected dependency. */
#[WatchScopes('probe_rows', 'probe_counts')]
class ScopedFeedRequestProbe
{
    #[InjectAsReadonly]
    protected \Semitexa\Core\Discovery\AttributeDiscovery $collaborator;

    public function collaboratorClass(): string
    {
        return $this->collaborator::class;
    }
}

/** A payload part, so the factory really produces a generated wrapper. */
trait ScopedFeedRequestPartProbe
{
    public function probePart(): string
    {
        return 'part';
    }
}
