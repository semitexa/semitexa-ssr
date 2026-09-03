<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Tenancy\Context\CoroutineContextStore;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapLocator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapStoragePath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sitemap:generate', description: 'Generate sitemap.xml from all registered providers')]
final class SitemapGenerateCommand extends Command
{
    #[InjectAsReadonly]
    protected SitemapGenerator $generator;

    protected function configure(): void
    {
        $this->setName('sitemap:generate')
             ->setDescription('Generate sitemap.xml from all registered providers')
             ->addOption(
                 name: 'output',
                 shortcut: 'o',
                 mode: InputOption::VALUE_REQUIRED,
                 description: 'Output directory for generated sitemap files',
             )
             ->addOption(
                 name: 'base-url',
                 mode: InputOption::VALUE_REQUIRED,
                 description: 'Base URL for sitemap entries (auto-detected if not specified)',
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sitemap Generation');

        try {
            if (!isset($this->generator)) {
                throw new \RuntimeException('Sitemap generator is not available.');
            }

            // `tenant:run <id> sitemap:generate` is the natural way to rebuild
            // one site's map, and it sets this context. Without reading it the
            // command wrote every tenant's sitemap into var/sitemap/default —
            // a file no domain is served from — and reported success.
            $tenantContext = $this->currentTenantContext();

            $outputOption = $input->getOption('output');
            $outputDir = is_string($outputOption) && $outputOption !== ''
                ? $outputOption
                : SitemapStoragePath::generatedDirectory($tenantContext);

            $baseUrlOption = $input->getOption('base-url');
            $baseUrl = is_string($baseUrlOption) && $baseUrlOption !== ''
                ? $baseUrlOption
                : AiSitemapLocator::originUrl(null, $tenantContext);

            $context = new SitemapGenerationContext(
                baseUrl: $baseUrl,
                tenantContext: $tenantContext,
            );
            $result = $this->generator->generateAndWrite($context, $outputDir);

            if (!$result->success) {
                $io->error('Sitemap generation failed.');
                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Sitemap generated: %d URLs in %d file(s) → %s',
                $result->totalUrls,
                $result->filesWritten,
                $result->primaryPath,
            ));
        } catch (\Throwable $e) {
            $io->error('sitemap:generate failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** The tenant this console process is running inside, if any. */
    private function currentTenantContext(): ?TenantContextInterface
    {
        $context = CoroutineContextStore::get();

        return $context instanceof TenantContextInterface ? $context : null;
    }
}
