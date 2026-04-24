<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Twig\DeferredTemplateCompatibilityValidator;
use Semitexa\Ssr\Twig\FrontendTwigCompatibilityIssue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'semitexa:lint:deferred-twig',
    description: 'Validate deferred Twig templates against the Semitexa frontend rendering subset.',
)]
final class LintDeferredTwigCommand extends Command
{
    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    protected function configure(): void
    {
        $this->setName('semitexa:lint:deferred-twig')
            ->setDescription('Validate deferred Twig templates against the Semitexa frontend rendering subset.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output violations as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $io = new SymfonyStyle($input, $output);

        try {
            ModuleTemplateRegistry::setModuleRegistry(isset($this->moduleRegistry) ? $this->moduleRegistry : new ModuleRegistry());
            TwigExtensionRegistry::setClassDiscovery(isset($this->classDiscovery) ? $this->classDiscovery : new ClassDiscovery());

            $validator = new DeferredTemplateCompatibilityValidator();
            $issues = $validator->validateAllDeferredTemplates();
        } catch (\Throwable $e) {
            if ($json) {
                $output->writeln((string) json_encode([
                    'clean' => false,
                    'errors' => [[
                        'template' => '',
                        'path' => '',
                        'line' => 1,
                        'construct' => 'runtime',
                        'name' => $e::class,
                        'message' => $e->getMessage(),
                    ]],
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $io->error('Deferred Twig compatibility lint failed: ' . $e->getMessage());
            }

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode([
                'clean' => $issues === [],
                'errors' => array_map(
                    static fn (FrontendTwigCompatibilityIssue $issue): array => $issue->toArray(),
                    $issues
                ),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $issues === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('Deferred Twig Frontend Compatibility');

        if ($issues === []) {
            $io->success('All deferred Twig templates are compatible with frontend deferred rendering.');
            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (FrontendTwigCompatibilityIssue $issue): array => [
                $issue->templatePath !== '' ? $issue->templatePath : $issue->templateName,
                (string) $issue->line,
                $issue->construct,
                $issue->name,
                $issue->message,
            ],
            $issues
        );

        $io->table(['Template', 'Line', 'Construct', 'Name', 'Issue'], $rows);
        $io->error(sprintf('%d deferred Twig compatibility issue(s) found.', count($issues)));

        return Command::FAILURE;
    }
}
