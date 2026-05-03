<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

final class SsrBootstrapStateKey
{
    public const DEFERRED_REQUEST_TABLE = 'ssr.deferred_request_table';
    public const ASYNC_RESOURCE_SSE_TABLES = 'ssr.async_resource_sse_tables';

    private function __construct()
    {
    }
}
