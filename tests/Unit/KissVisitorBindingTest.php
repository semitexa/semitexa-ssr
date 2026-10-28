<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Feature\FeatureFlagsInterface;
use Semitexa\Core\Log\LoggerInterface;
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

    /** A flag store that fails to build is named as such, not as a visitor that could not be established. */
    #[Test]
    public function a_feature_flag_failure_is_logged_as_its_own(): void
    {
        $parent = $this->createMock(ContainerInterface::class);
        $parent->method('has')->willReturnCallback(static fn (string $id): bool => $id === FeatureFlagsInterface::class);
        $parent->method('get')->willThrowException(new \RuntimeException('flag store is down'));
        $logger = new class implements LoggerInterface {
            /** @var list<string> */
            public array $errors = [];
            public function error(string $message, array $context = []): void { $this->errors[] = $message . ' (' . ($context['message'] ?? '') . ')'; }
            public function critical(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
        $visitor = new KissVisitor();
        (new \ReflectionProperty(KissVisitor::class, 'logger'))->setValue($visitor, $logger);

        (new \ReflectionMethod(KissVisitor::class, 'useFeatureFlags'))->invoke($visitor, new RequestScopedContainer($parent));

        self::assertSame(['Deferred render could not resolve the feature flags: feature() reads every flag as off (flag store is down)'], $logger->errors);
    }
}
