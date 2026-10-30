<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Stability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What semitexa/ssr promises (docs: get-started/stability): the symbols
 * marked @api, and only those. The list is pinned so the promise grows only
 * on purpose: a mark added or dropped shows here, in the same review as the
 * code. A dropped one is a break, owed a 90-day deprecation first.
 */
final class ApiSurfaceTest extends TestCase
{
    #[Test]
    public function the_api_surface_is_the_one_promised(): void
    {
        self::assertSame([
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::disableAutoRender()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::enableAutoRender()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::pageTitle()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::renderString()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::renderTemplate()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::seoKeywords()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::seoTag()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse::seoTagDefault()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse::renderTemplate()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse::withClientModules()',
            'Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse::withRenderContext()',
            'Semitexa\Ssr\Attribute\AsComponent',
            'Semitexa\Ssr\Attribute\AsDataProvider',
            'Semitexa\Ssr\Attribute\AsDeferred',
            'Semitexa\Ssr\Attribute\AsLayoutSlot',
            'Semitexa\Ssr\Attribute\AsSlotHandler',
            'Semitexa\Ssr\Attribute\AsSlotResource',
            'Semitexa\Ssr\Attribute\AsTwigExtension',
            'Semitexa\Ssr\Attribute\WithTransport',
            'Semitexa\Ssr\Domain\Contract\DataProviderInterface',
            'Semitexa\Ssr\Domain\Contract\TypedSlotHandlerInterface',
        ], self::marked(dirname(__DIR__, 3) . '/src', 'Semitexa\\Ssr\\'));
    }

    /**
     * A trait's members are copied into every class that uses it, and reflection
     * names that class as their declaring one: a member marked in the trait is
     * the trait's promise, not one more for each user, under its own name or an
     * alias. A method the class declares itself over the trait's is the class's.
     * A mark may sit up to four spaces in; further in, it is an example in the prose.
     */
    #[Test]
    public function a_trait_member_is_counted_once_on_the_trait(): void
    {
        $dir = sys_get_temp_dir() . '/orm-api-surface-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $ns = 'Probe' . bin2hex(random_bytes(4));
        // The probe namespace is outside composer's map: load its files from $dir, in whatever order the scan meets them.
        $load = static function (string $class) use ($dir, $ns): void {
            if (str_starts_with($class, $ns . '\\') && is_file($path = $dir . '/' . substr($class, strlen($ns) + 1) . '.php')) {
                require $path;
            }
        };
        spl_autoload_register($load);
        try {
            file_put_contents($dir . '/Stamps.php', "<?php\nnamespace {$ns};\n\n/** @api */\ntrait Stamps\n{\n    /** @api */\n    public string \$id = '';\n\n    /** @api */\n    public function touch(): void {}\n}\n");
            file_put_contents($dir . '/Model.php', "<?php\nnamespace {$ns};\n\n/** @api */\nfinal class Model\n{\n    use Stamps { touch as refresh; }\n}\n");
            file_put_contents($dir . '/Override.php', "<?php\nnamespace {$ns};\n\n/**\n *  @api\n */\nfinal class Override\n{\n    use Stamps;\n\n    /** @api */\n    public function touch(): void {}\n}\n");

            file_put_contents($dir . '/Prose.php', "<?php\nnamespace {$ns};\n\n/**\n * Mark it so:\n *\n *     @api\n */\nfinal class Prose\n{\n}\n");

            self::assertSame([
                "{$ns}\\Model",
                "{$ns}\\Override",
                "{$ns}\\Override::touch()",
                "{$ns}\\Stamps",
                "{$ns}\\Stamps::\$id",
                "{$ns}\\Stamps::touch()",
            ], self::marked($dir, $ns . '\\'));
        } finally {
            spl_autoload_unregister($load);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Every class-like whose own docblock carries @api, and every member marked
     * on its own (`Class::method()`, `Class::CONST`, `Class::$property`). Only a
     * file that mentions @api is loaded, so the scan needs nothing the package
     * merely suggests.
     *
     * @return list<string>
     */
    private static function marked(string $src, string $prefix): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, 'cannot read ' . $file->getPathname());
            if (!str_contains($source, '@api')) {
                continue;
            }
            $class = $prefix . str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                throw new \RuntimeException($file->getPathname() . ' mentions @api but declares no ' . $class);
            }
            $reflection = new \ReflectionClass($class);
            if (self::isApi($reflection->getDocComment())) {
                $found[] = $class;
            }
            $members = [
                ...array_map(static fn (\ReflectionMethod $m): array => [$m, $m->getName() . '()'], $reflection->getMethods()),
                ...array_map(static fn (\ReflectionClassConstant $c): array => [$c, $c->getName()], $reflection->getReflectionConstants()),
                ...array_map(static fn (\ReflectionProperty $p): array => [$p, '$' . $p->getName()], $reflection->getProperties()),
            ];
            foreach ($members as [$member, $name]) {
                // Inherited members count on their parent; a trait's copies, which reflection
                // reports as declared here, count on the trait. A copied method keeps the
                // trait's file (aliased or not); a property or constant only its name.
                $copied = $member instanceof \ReflectionMethod
                    ? $member->getFileName() !== $reflection->getFileName()
                    : self::fromTrait($reflection, $name);
                if ($member->getDeclaringClass()->getName() === $class && !$copied && self::isApi($member->getDocComment())) {
                    $found[] = $class . '::' . $name;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Whether a trait used here (or by one of its traits) declares the property `$name` or constant `NAME`.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function fromTrait(\ReflectionClass $class, string $name): bool
    {
        foreach ($class->getTraits() as $trait) {
            $declared = str_starts_with($name, '$') ? $trait->hasProperty(substr($name, 1)) : $trait->hasConstant($name);
            if ($declared || self::fromTrait($trait, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function isApi(string|false $doc): bool
    {
        return $doc !== false && preg_match('/^[ \t]*(?:\*[ \t]{0,4}|\/\*\*[ \t]{0,4})@api\b/m', $doc) === 1;
    }
}
