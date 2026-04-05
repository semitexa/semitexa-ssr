<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsTwigExtension
{
    public function __construct() {}
}
