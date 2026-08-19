<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;

/**
 * Characterization pin for the {@see AsyncResourceSseServer} public surface.
 *
 * The class is a static facade: every entry point is a `public static` method,
 * and 51 files across ssr, platform-ui, graphql, core, auth, demo and the
 * UiPlayground module call into it directly. `ep-slay-sse-god-class` decomposes
 * the 3083-line body into cohesive services behind interfaces while the facade
 * stays a thin delegating shim — which is only safe if the shim's shape is
 * provably unchanged.
 *
 * This test freezes that shape. It is deliberately brittle: any added, removed,
 * renamed or re-typed public member fails it. A failure is not a bug to paper
 * over — it is the signal to decide, explicitly, whether the change is a BC
 * break for those 51 call sites, and to update the frozen map in the same commit
 * that makes the decision.
 *
 * Scope note: this pins the shape only, never the behaviour. Behavioural
 * characterization lives in {@see AsyncResourceSseServerTest} and the sibling
 * Async tests, several of which reach private members through reflection —
 * those reflection entry points are the facade's hidden second contract and are
 * migrated per-extraction, not pinned here.
 */
final class AsyncResourceSseServerPublicSurfaceTest extends TestCase
{
    /**
     * Every public method declared on the facade, normalized to
     * `static function(<type> $name[=], …): <type>`.
     *
     * @var array<string, string>
     */
    private const FROZEN_METHODS = [
        'attachSubscription' => 'static function(Semitexa\Ssr\Domain\Model\SubscriptionRecord $record, Semitexa\Core\Pipeline\ReRun\ReRunContext $context): void',
        'broadcast' => 'static function(string $sessionId, string $handlerKey, object $resource): void',
        'createSessionCoroutine' => 'static function(callable $callback, string $sessionId): int|false',
        'deliver' => 'static function(string $sessionId, array $data): void',
        'deliverToAuthenticatedUsers' => 'static function(array $data): int',
        'deliverToUser' => 'static function(string $userId, array $data): int',
        'detachSubscription' => 'static function(string $streamingId): void',
        'handle' => 'static function(Swoole\Http\Request $request, Swoole\Http\Response $response): bool',
        // tk-facades-sse-server: the facade became a thin delegate over one
        // wired SseServer slot. `setInstance` is how worker boot pushes the
        // container singleton in; `instance` is how tests (and migrations)
        // reach the state that used to live in private statics here.
        'instance' => 'static function(): Semitexa\Ssr\Application\Service\Async\SseServer',
        'isReRunInProgress' => 'static function(): bool',
        'isSessionActive' => 'static function(string $sessionId): bool',
        'maxConnectionAgeSeconds' => 'static function(): int',
        'mintStreamId' => 'static function(): string',
        'publishScopeInvalidation' => 'static function(string $channel): void',
        'renderResource' => 'static function(object $resource): string',
        'serveResourceStream' => 'static function(Swoole\Http\Request $request, Swoole\Http\Response $response, string $sessionId, array $initialFrameData, ?Semitexa\Ssr\Domain\Model\SubscriptionRecord $record=, ?Semitexa\Core\Pipeline\ReRun\ReRunContext $context=, string $serverStreamId=): void',
        'setConnectCoordinator' => 'static function(?Semitexa\Ssr\Application\Service\Async\ConnectCoordinator $coordinator): void',
        'setDeferredBlockOrchestrator' => 'static function(?Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator $orchestrator): void',
        'setInstance' => 'static function(Semitexa\Ssr\Application\Service\Async\SseServer $server): void',
        'setReRunner' => 'static function(?Semitexa\Core\Pipeline\ReRun\ReRunnerInterface $reRunner): void',
        'setRequestTracer' => 'static function(?Semitexa\Core\Pipeline\RequestTracerInterface $tracer): void',
        'setRerunCoalescer' => 'static function(?Semitexa\Ssr\Application\Service\Async\RerunCoalescer $coalescer): void',
        'setServer' => 'static function(Swoole\Http\Server $server): void',
        'setSseServedPaths' => 'static function(array $paths): void',
        'setSubscriptionFactory' => 'static function(?Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface $factory): void',
        'setTables' => 'static function(Swoole\Table $sessionWorkerTable, Swoole\Table $deliverTable, ?Swoole\Table $pendingDeliverTable=): void',
        'setViewChangeCoalescer' => 'static function(?Semitexa\Ssr\Application\Service\Async\ViewChangeCoalescer $coalescer): void',
        'submitSubscribe' => 'static function(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot): bool',
        'submitUnsubscribe' => 'static function(string $sessionId, string $streamingId): bool',
        'submitViewChange' => 'static function(string $sessionId, array $params, ?string $streamingId=): bool',
        'traceMark' => 'static function(string $name, array $context=): void',
    ];

    /**
     * Public constants and their values. `TRANSPORT_MODE_*` are read by
     * platform-ui when it decides which transport marker to emit, and
     * `SAFE_BEARER_SESSION_ID_PATTERN` is the shape every subscription store
     * keys on — all three are wire-visible, so the values are pinned, not just
     * the names.
     *
     * @var array<string, string>
     */
    private const FROZEN_CONSTANTS = [
        'SAFE_BEARER_SESSION_ID_PATTERN' => '/\Asse_[a-f0-9]{32}\z/',
        'TRANSPORT_MODE_DRAIN' => 'drain',
        'TRANSPORT_MODE_LIVE' => 'live',
    ];

    #[Test]
    public function public_method_surface_is_unchanged(): void
    {
        self::assertSame(
            self::FROZEN_METHODS,
            self::describePublicMethods(),
            'The AsyncResourceSseServer public surface moved. 51 files call into this facade directly; '
            . 'confirm the change is intentional and BC for them, then update FROZEN_METHODS in this commit.',
        );
    }

    #[Test]
    public function public_constants_are_unchanged(): void
    {
        $actual = [];
        foreach ((new ReflectionClass(AsyncResourceSseServer::class))->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }

            $value = $constant->getValue();
            $actual[$constant->getName()] = is_string($value) ? $value : var_export($value, true);
        }
        ksort($actual);

        self::assertSame(self::FROZEN_CONSTANTS, $actual);
    }

    #[Test]
    public function every_entry_point_is_static(): void
    {
        // The facade has no instance API at all. This is the property that makes
        // the strangler safe: an extraction can move a body into a service and
        // leave a static one-liner behind without any caller ever holding an
        // instance whose class changed.
        $instanceMethods = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                self::declaredPublicMethods(),
                static fn (ReflectionMethod $method): bool => !$method->isStatic(),
            ),
        ));

        self::assertSame([], $instanceMethods);
    }

    /**
     * @return array<string, string>
     */
    private static function describePublicMethods(): array
    {
        $described = [];
        foreach (self::declaredPublicMethods() as $method) {
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = self::describeType($parameter->getType())
                    . ' $' . $parameter->getName()
                    . ($parameter->isOptional() ? '=' : '');
            }

            $described[$method->getName()] = ($method->isStatic() ? 'static ' : '')
                . 'function(' . implode(', ', $parameters) . '): '
                . self::describeType($method->getReturnType());
        }
        ksort($described);

        return $described;
    }

    /**
     * @return list<ReflectionMethod>
     */
    private static function declaredPublicMethods(): array
    {
        $class = new ReflectionClass(AsyncResourceSseServer::class);

        return array_values(array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName(),
        ));
    }

    private static function describeType(?\ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if (!$type instanceof ReflectionNamedType) {
            // Union / intersection types stringify to their declared form,
            // which is stable enough to pin (e.g. `int|false`).
            return (string) $type;
        }

        // `?X` and `X|null` are the same declaration to a caller; normalize to
        // the short form so a cosmetic rewrite does not read as a BC break.
        // `mixed` is implicitly nullable and must not gain a `?`.
        $nullable = $type->allowsNull() && $type->getName() !== 'mixed';

        return ($nullable ? '?' : '') . $type->getName();
    }
}
