<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapLocator;
use Semitexa\Ssr\Application\Service\Seo\Readiness\ReadinessSignal;
use Semitexa\Ssr\Application\Service\Seo\Readiness\SiteReadinessAudit;
use Semitexa\Ssr\Application\Service\Seo\Readiness\StreamProbe;
use Semitexa\Tenancy\Context\CoroutineContextStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Where a site stands on agent-readiness, read from its own responses.
 *
 * The answer is a list of named signals and their states — never a score.
 * Requests are anonymous, so a page behind auth shows as unreadable, which is
 * what it is to an agent. Another tenant is audited by passing its origin.
 */
#[AsCommand(name: 'site:readiness', description: "Report a site's agent-readiness signals from what it serves")]
final class SiteReadinessCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('site:readiness')
            ->setDescription("Report a site's agent-readiness signals from what it serves")
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Origin to audit (default: the site origin the sitemap uses)')
            ->addOption('page', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path to audit (repeatable; default: / plus a sitemap sample)')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Sitemap entries and llms.txt links to resolve', '10')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $urlOption = $input->getOption('url');
        $origin = is_string($urlOption) && $urlOption !== ''
            ? rtrim($urlOption, '/')
            : AiSitemapLocator::originUrl(null, $this->currentTenantContext());
        if (preg_match('#^https?://[^/]+$#i', $origin) !== 1) {
            $io->error(sprintf('site:readiness needs an origin like https://example.com, got "%s".', $origin));
            return Command::INVALID;
        }

        $sampleOption = $input->getOption('sample');
        $sample = is_numeric($sampleOption) ? max(0, (int) $sampleOption) : 10;

        /** @var list<string> $pages */
        $pages = $input->getOption('page');
        $pages = array_map(
            static fn (string $page): string => '/' . ltrim($page, '/'),
            $pages,
        );

        $signals = (new SiteReadinessAudit(\Closure::fromCallable(new StreamProbe())))->run($origin, $pages, $sample);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.site-readiness/v1',
                'origin' => $origin,
                'signals' => array_map(static fn (ReadinessSignal $s): array => $s->toArray(), $signals),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $io->title('Agent-readiness of ' . $origin);
        $io->text('Read from the site\'s own responses, anonymously. Signals and states only — there is no score.');
        $io->table(
            ['Subject', 'Signal', 'State', 'Detail'],
            array_map(
                static fn (ReadinessSignal $s): array => [$s->subject, $s->name, $s->state, $s->detail],
                $signals,
            ),
        );

        return Command::SUCCESS;
    }

    /** The tenant this console process is running inside, if any. */
    private function currentTenantContext(): ?TenantContextInterface
    {
        $context = CoroutineContextStore::get();

        return $context instanceof TenantContextInterface ? $context : null;
    }
}
