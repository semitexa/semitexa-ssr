<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Swoole\Table;

readonly class AsyncResourceSseTables
{
    public function __construct(
        public Table $sessionWorkerTable,
        public Table $deliverTable,
        public Table $pendingDeliverTable,
    ) {
    }
}
