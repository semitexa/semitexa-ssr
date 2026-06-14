<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Server\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\WireSseServedPathsListener;

final class WireSseServedPathsListenerTest extends TestCase
{
    #[Test]
    public function collects_only_transport_sse_route_paths_keying_on_transport_not_path(): void
    {
        $registry = new RouteRegistry();

        // /__semitexa_kiss is an SSE route (declares transport: Sse) — kept.
        $registry->register([
            'path' => '/__semitexa_kiss',
            'methods' => ['GET'],
            'name' => 'ssr.kiss',
            'class' => 'Fixture\\KissPayload',
            'type' => 'http-request',
            'transport' => TransportType::Sse->value,
        ]);
        // An own-route, non-kiss SSE endpoint — kept on equal footing (proves the
        // collection keys on transport, not on the kiss path).
        $registry->register([
            'path' => '/ui-playground/admin/leads/grid-stream',
            'methods' => ['GET'],
            'name' => 'leads.grid.stream',
            'class' => 'Fixture\\GridStreamPayload',
            'type' => 'http-request',
            'transport' => TransportType::Sse->value,
        ]);
        // A plain HTTP route on a sibling path — excluded.
        $registry->register([
            'path' => '/ui-playground/admin/leads/grid-data',
            'methods' => ['GET'],
            'name' => 'leads.grid.data',
            'class' => 'Fixture\\GridDataPayload',
            'type' => 'http-request',
            'transport' => TransportType::Http->value,
        ]);
        // A route with no transport key at all — excluded (defaults to non-Sse).
        $registry->register([
            'path' => '/',
            'methods' => ['GET'],
            'name' => 'home',
            'class' => 'Fixture\\HomePayload',
            'type' => 'http-request',
        ]);

        $paths = WireSseServedPathsListener::collectSsePaths($registry);

        sort($paths);
        self::assertSame(
            ['/__semitexa_kiss', '/ui-playground/admin/leads/grid-stream'],
            $paths,
        );
    }

    #[Test]
    public function returns_empty_when_no_route_declares_transport_sse(): void
    {
        $registry = new RouteRegistry();
        $registry->register([
            'path' => '/',
            'methods' => ['GET'],
            'class' => 'Fixture\\HomePayload',
            'type' => 'http-request',
            'transport' => TransportType::Http->value,
        ]);

        self::assertSame([], WireSseServedPathsListener::collectSsePaths($registry));
    }

    #[Test]
    public function deduplicates_repeated_sse_paths(): void
    {
        $registry = new RouteRegistry();
        // Same path registered under two methods (e.g. GET + HEAD) must collapse
        // to a single served-path entry.
        foreach (['GET', 'HEAD'] as $method) {
            $registry->register([
                'path' => '/__semitexa_kiss',
                'methods' => [$method],
                'class' => 'Fixture\\KissPayload',
                'type' => 'http-request',
                'transport' => TransportType::Sse->value,
            ]);
        }

        self::assertSame(['/__semitexa_kiss'], WireSseServedPathsListener::collectSsePaths($registry));
    }
}
