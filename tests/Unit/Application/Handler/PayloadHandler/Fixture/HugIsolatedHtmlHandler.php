<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

/**
 * The handler of the `html` profile alone: the page it draws needs a tenant.
 *
 * The attribute by its full name: docs:reference:generate takes the first
 * short-named use it finds as the attribute's example, and this probe is not one.
 */
#[\Semitexa\Tenancy\Attribute\TenantIsolated]
final class HugIsolatedHtmlHandler
{
}
