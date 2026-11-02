<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Command;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Scan\FileScanner;
use Mfd\AdaptiveVideo\Scan\VersionScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Main way to detect videos; meant to run as scheduler task. It only
 * queues messages, the encoding happens in the messenger worker.
 */
#[AsCommand('adaptive-video:scan', 'Queue referenced video files that have no version for their current content and profile')]
final class ScanCommand extends Command
{
    public function __construct(
        private readonly FileScanner $fileScanner,
        private readonly VersionScheduler $versionScheduler,
        private readonly ExtensionSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the files that would be queued');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        if (!$dryRun && !$this->settings->processingEnabled) {
            $io->note('Processing is disabled in this environment (extension setting "processingEnabled"). Nothing is queued.');
            return Command::SUCCESS;
        }

        $found = 0;
        $queued = 0;
        $notFound = 0;
        foreach ($this->fileScanner->findFilesWithoutVersion() as $file) {
            $found++;
            if (!$this->fileScanner->fileExists($file)) {
                $notFound++;
                $io->writeln(
                    sprintf('File %d: %s not found in storage %d, skipped', $file['uid'], $file['identifier'], $file['storage']),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('File %d: %s', $file['uid'], $file['identifier']));
                continue;
            }

            $versionUid = $this->versionScheduler->schedule($file['uid'], $file['sha1']);
            if ($versionUid !== null) {
                $queued++;
                $io->writeln(
                    sprintf('File %d: %s queued as version %d', $file['uid'], $file['identifier'], $versionUid),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }
        }

        $io->success($dryRun
            ? sprintf('%d file(s) would be queued.', $found - $notFound)
            : sprintf('%d of %d file(s) queued.', $queued, $found));
        if ($notFound > 0) {
            $io->warning(sprintf('%d file(s) skipped because they do not exist in their storage. Use -v to list them.', $notFound));
        }

        return Command::SUCCESS;
    }
}
