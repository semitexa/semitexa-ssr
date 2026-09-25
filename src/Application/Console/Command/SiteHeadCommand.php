<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Ssr\Domain\Model\SiteHead;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show or change the typed values a site puts in its own <head>.
 *
 * Values are tenant-scoped: `tenant:run <tenant> site:head --set …` writes that
 * site's. A value that does not match its vendor's token shape is refused here,
 * before it is stored, rather than skipped at render time.
 */
#[AsCommand(name: 'site:head', description: "Show or set the site's head values (analytics id, verification tokens)")]
final class SiteHeadCommand extends Command
{
    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    protected function configure(): void
    {
        $this->setName('site:head')
            ->setDescription("Show or set the site's head values (analytics id, verification tokens)")
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'key=value to store (repeatable)')
            ->addOption('unset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'key to remove (repeatable)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->setHelp(
                'Keys: ' . implode(', ', array_keys(SiteHead::KEYS)) . "\n\n"
                . 'The scripts carry the request\'s CSP nonce, but a site that sends a Content-Security-Policy '
                . 'must still allow the vendor\'s hosts (connect-src, and script-src for the loader): '
                . 'www.googletagmanager.com and *.google-analytics.com for GA4, plausible.io for Plausible. '
                . 'Without them the page loads and analytics stays silent.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $sets */
        $sets = $input->getOption('set');
        /** @var list<string> $unsets */
        $unsets = $input->getOption('unset');

        $writes = [];
        $errors = [];
        foreach ($sets as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $errors[] = sprintf('--set expects key=value, got "%s"', $pair);
                continue;
            }
            $key = trim(substr($pair, 0, $eq));
            $value = trim(substr($pair, $eq + 1));
            $why = SiteHead::whyRejected($key, $value);
            if ($why !== null) {
                $errors[] = $why;
                continue;
            }
            $writes[$key] = SiteHead::normalize($key, $value);
        }
        // A stored key outside KEYS (written directly, or dropped by a later
        // release) is logged as malformed on page views; --unset must be able
        // to remove it, or only a hand-edit of the database could.
        $stored = $this->settings->getAll(SiteHead::SETTINGS_MODULE);
        foreach ($unsets as $key) {
            if (!array_key_exists($key, SiteHead::KEYS) && !array_key_exists($key, $stored)) {
                $errors[] = (string) SiteHead::whyRejected($key, null);
            }
        }

        // All or nothing: one bad value must not leave the others half-applied.
        if ($errors !== []) {
            foreach ($errors as $error) {
                $io->error($error);
            }

            return Command::FAILURE;
        }

        foreach ($writes as $key => $value) {
            if ($value === '') {
                $this->settings->remove(SiteHead::SETTINGS_MODULE, $key);
            } else {
                $this->settings->set(SiteHead::SETTINGS_MODULE, $key, $value);
            }
        }
        foreach ($unsets as $key) {
            $this->settings->remove(SiteHead::SETTINGS_MODULE, $key);
        }

        $head = SiteHead::fromSettings($this->settings->getAll(SiteHead::SETTINGS_MODULE));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'values' => (object) $head->values,
                'rejected' => (object) $head->rejected,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = [];
        foreach (array_keys(SiteHead::KEYS + $head->rejected) as $key) {
            $rows[] = [$key, $head->get($key) ?? (isset($head->rejected[$key]) ? '<error>malformed, not rendered</error>' : '—')];
        }
        $io->table(['key', 'value'], $rows);

        return Command::SUCCESS;
    }
}
