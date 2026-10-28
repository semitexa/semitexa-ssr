<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;

/**
 * The escaping is not part of Twig's cache key: after an upgrade that changed
 * it, compiled templates kept the old escaping until someone ran cache:clear.
 */
final class TwigCompileProfileTest extends TestCase
{
    #[Test]
    public function compiled_templates_live_in_a_directory_of_the_settings_they_were_compiled_with(): void
    {
        $catalog = (new \ReflectionClass(ModuleTemplateCatalog::class))->newInstanceWithoutConstructor();
        $dir = (new \ReflectionMethod(ModuleTemplateCatalog::class, 'getWritableCacheDir'))->invoke($catalog);
        $profile = (new \ReflectionClassConstant(ModuleTemplateCatalog::class, 'COMPILE_PROFILE'))->getValue();

        self::assertIsString($dir);
        self::assertSame($profile, basename($dir));
        self::assertDirectoryExists($dir);
    }
}
