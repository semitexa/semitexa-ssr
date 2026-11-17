<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Tests\Support\StaticState;
use Semitexa\Ssr\Application\Service\Template\ContentKeyedFilesystemLoader;
use Semitexa\Ssr\Application\Service\Template\ThemeAwareTwigLoader;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;

/**
 * tk-twig-cache-outlives-a-code-update (the Guard project, production, 2026-11-17):
 * a deploy by `git archive | tar` lands every file with the commit's time. The old
 * code had compiled the old partial after that time, so the new partial looked
 * older than its compiled copy, auto_reload kept the copy, and the page answered
 * 500 ("Macro "trusted" is not defined") until cache:clear.
 *
 * Each render below is a fresh worker: a new loader and environment over the same
 * compiled cache directory.
 */
final class ACodeUpdateIsNeverServedAnOlderCompiledTemplateTest extends TestCase
{
    private string $dir = '';
    private string|false $cwd = false;
    /** @var array{class: class-string, values: array<string, mixed>}|null */
    private ?array $projectRoot = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/twig-content-key-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/templates', 0o777, true);
        mkdir($this->dir . '/cache', 0o777, true);
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        if ($this->projectRoot !== null) {
            StaticState::restore($this->projectRoot);
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    #[Test]
    public function a_template_deployed_with_an_older_mtime_is_compiled_again(): void
    {
        $template = $this->dir . '/templates/partial.html.twig';
        file_put_contents($template, 'old partial');

        // The premise, as production met it: Twig's own loader keeps the older-looking copy.
        self::assertSame('old partial', $this->render(new FilesystemLoader([$this->dir . '/templates']), 'partial.html.twig'));
        $this->deploy($template, 'new partial');
        self::assertSame('old partial', $this->render(new FilesystemLoader([$this->dir . '/templates']), 'partial.html.twig'), 'Twig alone serves the stale copy');

        self::assertSame('new partial', $this->render(new ContentKeyedFilesystemLoader([$this->dir . '/templates']), 'partial.html.twig'));
        $this->deploy($template, 'newer partial');
        self::assertSame('newer partial', $this->render(new ContentKeyedFilesystemLoader([$this->dir . '/templates']), 'partial.html.twig'));
    }

    #[Test]
    public function a_theme_override_deployed_with_an_older_mtime_is_compiled_again(): void
    {
        $root = $this->dir . '/project';
        mkdir($root . '/src/modules', 0o777, true);
        mkdir($root . '/src/theme/dark/Guard/templates/partials', 0o777, true);
        file_put_contents($root . '/composer.json', '{}');
        $this->cwd = getcwd();
        $this->projectRoot = StaticState::snapshot(ProjectRoot::class);
        chdir($root);
        ProjectRoot::reset();
        $override = $root . '/src/theme/dark/Guard/templates/partials/path.html.twig';
        file_put_contents($override, 'old override');

        $themed = fn (): ThemeAwareTwigLoader => new ThemeAwareTwigLoader(
            new ContentKeyedFilesystemLoader([$this->dir . '/templates']),
            static fn (): array => ['dark'],
        );
        self::assertSame('old override', $this->render($themed(), '@Guard/partials/path.html.twig'));
        $this->deploy($override, 'new override');
        self::assertSame('new override', $this->render($themed(), '@Guard/partials/path.html.twig'));
    }

    private function render(LoaderInterface $loader, string $name): string
    {
        return (new Environment($loader, ['cache' => $this->dir . '/cache', 'auto_reload' => true]))->render($name);
    }

    /** New content, stamped an hour before the compiled copy, as `git archive | tar` stamps it. */
    private function deploy(string $template, string $content): void
    {
        file_put_contents($template, $content);
        touch($template, time() - 3600);
        clearstatcache();
    }
}
