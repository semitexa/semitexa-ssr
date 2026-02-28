<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Theme;

use Semitexa\Core\Tenant\Layer\ThemeValue;
use Semitexa\Core\Tenant\Layer\ThemeLayer;
use Semitexa\Core\Tenant\TenantContextInterface;

final class TenantContextThemeResolver implements ThemeResolverInterface
{
    public function __construct(
        private readonly ?TenantContextInterface $tenantContext = null,
    ) {}

    public function resolve(): ThemeValue
    {
        if ($this->tenantContext === null) {
            return ThemeValue::default();
        }

        $themeValue = $this->tenantContext->getLayer(new ThemeLayer());
        
        if ($themeValue !== null) {
            return $themeValue;
        }

        return ThemeValue::default();
    }
}
