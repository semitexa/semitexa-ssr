<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Swoole\Table;

#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::PreStart->value,
    priority: -10,
    requiresContainer: false,
)]
final class CreateAsyncResourceSseTablesListener implements ServerLifecycleListenerInterface
{
    private const TABLE_COLUMN_INT_SIZE = 4;
    private const TABLE_COLUMN_SESSION_ID_MAX_LENGTH = 128;

    public function handle(ServerLifecycleContext $context): void
    {
        if ($context->bootstrapState === null || !class_exists(Table::class, false)) {
            return;
        }

        $env = $context->environment;

        $sessionWorkerTable = new Table($env->swooleSseWorkerTableSize);
        $sessionWorkerTable->column('worker_id', Table::TYPE_INT, self::TABLE_COLUMN_INT_SIZE);
        $sessionWorkerTable->create();

        $deliverTable = new Table($env->swooleSseDeliverTableSize);
        $deliverTable->column('session_id', Table::TYPE_STRING, self::TABLE_COLUMN_SESSION_ID_MAX_LENGTH);
        $deliverTable->column('worker_id', Table::TYPE_INT, self::TABLE_COLUMN_INT_SIZE);
        $deliverTable->column('payload', Table::TYPE_STRING, $env->swooleSsePayloadMaxBytes);
        $deliverTable->create();

        $pendingDeliverTable = new Table($env->swooleSseDeliverTableSize);
        $pendingDeliverTable->column('session_id', Table::TYPE_STRING, self::TABLE_COLUMN_SESSION_ID_MAX_LENGTH);
        $pendingDeliverTable->column('payload', Table::TYPE_STRING, $env->swooleSsePayloadMaxBytes);
        $pendingDeliverTable->create();

        $context->bootstrapState->set(
            SsrBootstrapStateKey::ASYNC_RESOURCE_SSE_TABLES,
            new AsyncResourceSseTables($sessionWorkerTable, $deliverTable, $pendingDeliverTable),
        );
    }
}
