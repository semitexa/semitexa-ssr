<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Console\Command\LintDeferredSlotsCommand;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

/**
 * A template the audit cannot open is not a template it can clear.
 *
 * The gap this pins is the shape every silent check has: the file is skipped,
 * no finding is produced, and `clean: true` comes back — `--strict` included,
 * because there is nothing to block on. If that one file held the only
 * disagreement, the audit reported a clean tree for work it never did.
 */
final class DeferredSlotAuditReadsEveryTemplateTest extends TestCase
{
    private string $dir;

    private ?ModuleTemplateCatalog $previous = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/slot-audit-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        $this->previous = $this->catalogProperty()->getValue();
    }

    protected function tearDown(): void
    {
        @chmod($this->dir . '/page.html.twig', 0o644);
        @unlink($this->dir . '/page.html.twig');
        @rmdir($this->dir);

        $this->catalogProperty()->setValue(null, $this->previous);
    }

    #[Test]
    public function anUnreadableTemplateFailsTheAuditInsteadOfVanishing(): void
    {
        file_put_contents($this->dir . '/page.html.twig', '{{ layout_slot("x") }}');
        chmod($this->dir . '/page.html.twig', 0o000);

        if (is_readable($this->dir . '/page.html.twig')) {
            self::markTestSkipped('Running as a user that reads mode-000 files; the unreadable case cannot be staged.');
        }

        $this->bindModulePaths(['Probe' => ['path' => $this->dir]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/page\.html\.twig/');

        $sources = new \ReflectionMethod(LintDeferredSlotsCommand::class, 'templateSources');
        $sources->invoke(new LintDeferredSlotsCommand());
    }

    #[Test]
    public function aReadableTemplateIsReturnedByItsPath(): void
    {
        file_put_contents($this->dir . '/page.html.twig', '{{ layout_slot("x") }}');
        $this->bindModulePaths(['Probe' => ['path' => $this->dir]]);

        $sources = new \ReflectionMethod(LintDeferredSlotsCommand::class, 'templateSources');

        self::assertSame(
            [$this->dir . '/page.html.twig' => '{{ layout_slot("x") }}'],
            $sources->invoke(new LintDeferredSlotsCommand())
        );
    }

    /** @param array<string, array{path: string}> $paths */
    private function bindModulePaths(array $paths): void
    {
        $catalog = new ModuleTemplateCatalog();

        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'modulePaths'))->setValue($catalog, $paths);
        (new \ReflectionProperty(ModuleTemplateCatalog::class, 'initialized'))->setValue($catalog, true);

        ModuleTemplateRegistry::setCatalog($catalog);
    }

    private function catalogProperty(): \ReflectionProperty
    {
        return new \ReflectionProperty(ModuleTemplateRegistry::class, 'catalog');
    }
}
