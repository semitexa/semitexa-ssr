<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentCatalog;
use Semitexa\Ssr\Application\Service\Component\ComponentReferenceScanner;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'lint:components',
    description: 'Check that every component(\'…\') a template calls names a registered #[AsComponent].',
)]
final class LintComponentsCommand extends Command
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: a component is declared by name in #[AsComponent(name: …)] and used by that name in component(\'…\'), and the two drift apart on a typo or a one-sided rename. The renderer answered an unknown name with an HTML comment, so the page had a hole only view-source showed; development now throws, but only when that page is opened — this finds it before. Learned 2026-10-07, asking whether a component name repeated in a template is a second source of truth: it is a reference, and references need a check.';

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    #[InjectAsReadonly]
    protected ComponentCatalog $catalog;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    protected function configure(): void
    {
        $this->setName('lint:components')
            ->setDescription('Check that every component(\'…\') a template calls names a registered #[AsComponent].')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        ModuleTemplateRegistry::setModuleRegistry(isset($this->moduleRegistry) ? $this->moduleRegistry : new ModuleRegistry());
        // The template catalog builds Twig to answer, and Twig needs its extensions found.
        TwigExtensionRegistry::setClassDiscovery(isset($this->classDiscovery) ? $this->classDiscovery : new ClassDiscovery());

        $roots = array_values(array_map(static fn (array $module): string => $module['path'], ModuleTemplateRegistry::getModulePaths()));
        $known = array_keys($this->catalog->all());
        $issues = (new ComponentReferenceScanner())->unknownReferences($roots, $known);

        if ($json) {
            $output->writeln((string) json_encode(['clean' => $issues === [], 'errors' => $issues], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $issues === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        if ($issues === []) {
            $io->success(sprintf('Every component(\'…\') in %d template roots names one of %d registered components.', count($roots), count($known)));

            return Command::SUCCESS;
        }
        foreach ($issues as $issue) {
            $io->writeln(sprintf(
                '  %s:%d  component(\'%s\') — no #[AsComponent] has this name%s',
                $issue['path'],
                $issue['line'],
                $issue['name'],
                $issue['suggestion'] === null ? '' : sprintf("; did you mean '%s'?", $issue['suggestion']),
            ));
        }
        $io->error(sprintf('%d component reference(s) name nothing registered.', count($issues)));

        return Command::FAILURE;
    }
}
