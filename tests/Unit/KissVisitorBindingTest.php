<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Ssr\Application\Service\KissVisitor;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;

/**
 * Work on a page's KISS stream mints signed UI contexts (a deferred component,
 * a re-render). The route pipeline binds them to the visitor's session and
 * tenant in an AuthCheck listener the stream never runs, so they went out
 * unbound — presentable from any session.
 */
final class KissVisitorBindingTest extends TestCase
{
    /** @var array{session: SessionInterface|string|null, t: string}|null */
    private ?array $previousBinding = null;

    protected function setUp(): void
    {
        $this->previousBinding = SignedContextBinding::snapshot();
    }

    protected function tearDown(): void
    {
        SignedContextBinding::restore($this->previousBinding);
    }

    #[Test]
    public function the_stream_binds_its_contexts_to_the_visitor_s_session_and_tenant(): void
    {
        $scope = new RequestScopedContainer($this->createMock(ContainerInterface::class));
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn('sess-visitor');
        $scope->set(SessionInterface::class, $session);
        $scope->set(TenantContextInterface::class, new class implements TenantContextInterface {
            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface { return null; }
            public function hasLayer(TenantLayerInterface $layer): bool { return false; }
            public function getTenantId(): string { return 'acme'; }
        });
        SignedContextBinding::clear();

        (new \ReflectionMethod(KissVisitor::class, 'bindSignedContexts'))->invoke(new KissVisitor(), $scope);

        self::assertSame(['s' => 'sess-visitor', 't' => 'acme'], SignedContextBinding::current());
    }
}
