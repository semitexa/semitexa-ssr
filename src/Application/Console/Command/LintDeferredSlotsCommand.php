<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Layout\DeferralIntentAudit;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Domain\Model\DeferralIntentFinding;
use Semitexa\Ssr\Domain\Model\DeferralIntentKind;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Say so when `deferred: true` defers nothing.
 *
 * A slot is deferred only when the resource declares it AND the template
 * defers it. Either half alone renders the region inline, silently, and the
 * declaration reads to everyone afterwards as a decision that was taken.
 *
 * REPORTED, NOT FAILED, and that is deliberate. Rendering inline is a
 * legitimate choice; 50 red lines on an existing project would be switched off
 * within the hour, and a switched-off check reads as coverage. `--strict` is
 * for a project that has decided its declarations must mean something.
 *
 * Sibling of `lint:deferred-twig`, which validates the client-renderable Twig
 * subset for `mode: template` slots. That one asks whether a deferred slot's
 * template CAN render on the client; this one asks whether the slot is
 * deferred at all.
 */
#[AsCommand(
    name: 'lint:deferred-slots',
    description: 'Report slots whose deferred declaration and page template disagree.',
)]
final class LintDeferredSlotsCommand extends Command
{
    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    protected function configure(): void
    {
        $this->setName('lint:deferred-slots')
            ->setDescription('Report slots whose deferred declaration and page template disagree.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with failure when anything disagrees')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strict = (bool) $input->getOption('strict');
        $json = (bool) $input->getOption('json');

        try {
            ModuleTemplateRegistry::setModuleRegistry(isset($this->moduleRegistry) ? $this->moduleRegistry : new ModuleRegistry());
            TwigExtensionRegistry::setClassDiscovery(isset($this->classDiscovery) ? $this->classDiscovery : new ClassDiscovery());

            $findings = (new DeferralIntentAudit())->audit(
                $this->declaredDeferredSlots(),
                $this->templateSources(),
            );
        } catch (\Throwable $e) {
            if ($json) {
                $output->writeln((string) json_encode([
                    'clean' => false,
                    'error' => $e->getMessage(),
                    'findings' => [],
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $io->error('Deferred slot audit failed: ' . $e->getMessage());
            }

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode([
                'clean' => $findings === [],
                'strict' => $strict,
                'findings' => array_map(
                    static fn (DeferralIntentFinding $f): array => $f->toArray(),
                    $findings
                ),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $findings === [] || !$strict ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('Deferred slots: declaration vs template');

        if ($findings === []) {
            $io->success('Every slot that declares deferred is deferred by its page, and vice versa.');

            return Command::SUCCESS;
        }

        $io->table(
            ['What', 'Slot', 'Declared in', 'Consequence'],
            array_map(
                static fn (DeferralIntentFinding $f): array => [
                    $f->kind === DeferralIntentKind::DeclaredButNeverDeferred ? 'declared, never deferred' : 'deferred, never declared',
                    $f->slot,
                    $f->origin,
                    $f->consequence,
                ],
                $findings
            )
        );

        $message = sprintf('%d slot(s) where the declaration and the template disagree.', count($findings));

        if ($strict) {
            $io->error($message);

            return Command::FAILURE;
        }

        $io->warning($message . ' Reported, not blocking — rendering inline is a choice, not knowing which one you made is not.');

        return Command::SUCCESS;
    }

    /** @return array<string, string> slot name => the resource that declared it */
    private function declaredDeferredSlots(): array
    {
        $declared = [];

        foreach (LayoutSlotRegistry::getAllDeferredSlots() as $slot) {
            $declared[$slot->slotId] ??= $slot->resourceClass ?? ('handle ' . $slot->pageHandle);
        }

        return $declared;
    }

    /**
     * Every Twig template the application can render, by path.
     *
     * Read from disk rather than through Twig: the question is what the SOURCE
     * says, and a compiled template has already lost the call.
     *
     * @return array<string, string>
     */
    private function templateSources(): array
    {
        $sources = [];

        foreach (ModuleTemplateRegistry::getModulePaths() as $module) {
            // getModulePaths() declares `path` as a present string, so the
            // null-coalesce and the is_string() guard that used to stand here
            // were dead. What is NOT guaranteed is that the directory exists.
            $path = $module['path'];
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }

                $contents = @file_get_contents($file->getPathname());

                // A template it cannot READ is not a template it can clear.
                // Omitted silently, the one file holding the disagreement
                // leaves the audit reporting `clean: true` — and `--strict`
                // succeeds too, because no finding exists to block on. The
                // catch around this reports the failure instead.
                if ($contents === false) {
                    throw new \RuntimeException(sprintf(
                        'Could not read the template %s. The audit cannot clear a file it cannot open.',
                        $file->getPathname()
                    ));
                }

                $sources[$file->getPathname()] = $contents;
            }
        }

        return $sources;
    }
}
