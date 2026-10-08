<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthBootstrapperFactoryInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;
use Semitexa\Core\RequestFactory;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\Support\Row;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;

/**
 * Who work done on a page's KISS stream is done as (tk-ls-kiss-visitor).
 *
 * The stream is served outside the route pipeline, so nothing there has said
 * who its visitor is: a deferred region behind a permission rendered as for a
 * guest. {@see establish()} re-establishes, in the running coroutine, the
 * visitor of the browser that opened the stream — its session first, then who
 * it is, best-effort, as for a public page.
 */
#[AsService]
final class KissVisitor
{
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    /** The request running in this coroutine, if a Swoole request is: the stream's own browser. */
    public static function ofCurrentRequest(): ?Request
    {
        $current = SwooleBootstrap::getCurrentSwooleRequestResponse();
        $swooleRequest = $current[0] ?? null;

        return $swooleRequest instanceof \Swoole\Http\Request ? RequestFactory::fromSwoole($swooleRequest) : null;
    }

    public function establish(?Request $visitor): void
    {
        if ($visitor === null) {
            return;
        }
        try {
            // One request scope for both: the session SessionPhase loads is the
            // one the session auth handler reads the visitor from.
            $scope = RequestScopedContainer::forCurrentExecution($this->container);
            (new RouteExecutor($scope, $this->container, $this->bootstrapper($scope)))->establishVisitor($visitor);
            $this->bindSignedContexts($scope);
        } catch (\Throwable $e) {
            SseSessionCoroutines::rethrowIfCancellation($e);
            $this->logger->error('Deferred render could not establish the visitor', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Signed UI contexts minted by work on the stream are bound to the visitor's
     * session and tenant, as on the page. The route pipeline binds them in an
     * AuthCheck listener the stream never runs; without this, a context minted
     * in a deferred or re-rendered component carried no binding at all and any
     * session could present it. Read from the same request scope, through the
     * same contracts, as that listener — a different source would mint contexts
     * HUG later refuses.
     */
    private function bindSignedContexts(RequestScopedContainer $scope): void
    {
        $session = $scope->has(SessionInterface::class) ? $scope->get(SessionInterface::class) : null;
        $tenant = $scope->has(TenantContextInterface::class) ? $scope->get(TenantContextInterface::class) : null;
        $tenantId = $tenant instanceof TenantContextInterface && method_exists($tenant, 'getTenantId') ? Row::asString($tenant->getTenantId()) : '';
        SignedContextBinding::bind($session instanceof SessionInterface ? $session : null, $tenantId);
    }

    /**
     * Built as the application builds it for the route pipeline — through its
     * factory (it is not a container service) — over the given request scope.
     */
    private function bootstrapper(RequestScopedContainer $scope): ?AuthBootstrapperInterface
    {
        $factory = $this->container->has(AuthBootstrapperFactoryInterface::class)
            ? $this->container->get(AuthBootstrapperFactoryInterface::class)
            : null;

        return $factory instanceof AuthBootstrapperFactoryInterface ? $factory->create($this->container, $scope) : null;
    }
}
