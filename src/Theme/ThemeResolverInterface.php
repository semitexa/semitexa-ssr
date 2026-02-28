<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Theme;

use Semitexa\Core\Tenant\Layer\ThemeValue;
use Semitexa\Core\Tenant\TenantContextInterface;

interface ThemeResolverInterface
{
    public function resolve(): ThemeValue;
}
