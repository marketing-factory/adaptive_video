<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Command;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Model\Version;
use Mfd\AdaptiveVideo\Domain\Model\VersionStatus;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Processing\FfmpegEncoder;
use Mfd\AdaptiveVideo\Storage\ArtifactStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;

/**
 * Read-only overview for operations: without argument the state of all
 * versions, with a file uid the versions of that file and which one is active.
 */
#[AsCommand('adaptive-video:status', 'Show the processing state of all videos or the versions of one file')]
final class StatusCommand extends Command
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly VersionRepository $versionRepository,
        private readonly FileIndexRepository $fileIndexRepository,
        private readonly ArtifactStorageInterface $artifactStorage,
        private readonly ExtensionSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('fileUid', InputArgument::OPTIONAL, 'Show the versions of this sys_file uid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileUid = $input->getArgument('fileUid');
        if ($fileUid === null) {
            $this->showOverview($io);
            return Command::SUCCESS;
        }

        if (!ctype_digit((string)$fileUid)) {
            $io->error('The file uid must be a positive integer.');
            return Command::INVALID;
        }

        return $this->showFile($io, (int)$fileUid);
    }

    private function showOverview(SymfonyStyle $io): void
    {
        $io->definitionList(
            ['Processing' => $this->settings->processingEnabled ? 'enabled' : 'disabled'],
            ['Delivery' => $this->settings->deliveryEnabled ? 'enabled' : 'disabled'],
        );

        $counts = $this->versionRepository->countByStatus();
        $io->table(['Status', 'Versions'], array_map(null, array_keys($counts), array_values($counts)));

        $processing = $this->versionRepository->findByStatus(VersionStatus::Processing);
        if ($processing !== []) {
            $io->section('Processing');
            $now = time();
            $io->table(
                ['Version', 'File', 'Started', 'Runtime'],
                array_map(fn(Version $version): array => [
                    $version->uid,
                    $this->formatFile($version->fileUid),
                    date(self::DATE_FORMAT, $version->processingStartedAt),
                    Helper::formatTime($now - $version->processingStartedAt)
                        . ($now - $version->processingStartedAt > $this->settings->maxRuntime ? ' (exceeds maximum runtime, worker probably aborted)' : ''),
                ], $processing)
            );
        }

        foreach ([VersionStatus::Failed, VersionStatus::Skipped] as $status) {
            $versions = $this->versionRepository->findByStatus($status);
            // Skipped versions are expected, e.g. short videos
            if ($versions === [] || ($status === VersionStatus::Skipped && !$io->isVerbose())) {
                continue;
            }

            $io->section(ucfirst($status->value));
            $io->table(
                ['Version', 'File', 'Completed', 'Reason'],
                array_map(fn(Version $version): array => [
                    $version->uid,
                    $this->formatFile($version->fileUid),
                    date(self::DATE_FORMAT, $version->completedAt),
                    $version->errorMessage,
                ], $versions)
            );
        }

        if ($counts[VersionStatus::Failed->value] > 0) {
            $io->note('To retry a failed version, delete it from table ' . VersionRepository::TABLE_NAME . '. The next scan queues the file again.');
        }

        if ($counts[VersionStatus::Skipped->value] > 0 && !$io->isVerbose()) {
            $io->writeln('Use -v to list skipped versions.');
        }
    }

    private function showFile(SymfonyStyle $io, int $fileUid): int
    {
        $versions = $this->versionRepository->findByFileUid($fileUid);
        $file = $this->fileIndexRepository->findOneByUid($fileUid);
        if ($file === false && $versions === []) {
            $io->error(sprintf('File %d does not exist and has no versions.', $fileUid));
            return Command::FAILURE;
        }

        $sha1 = $file === false ? '' : (string)$file['sha1'];
        $io->definitionList(
            ['File' => $file === false ? sprintf('%d (record does not exist anymore)', $fileUid) : $this->formatFile($fileUid)],
            ['Content (sha1)' => $sha1],
        );
        if ($versions === []) {
            $io->writeln('No versions. The scan creates one if the file is referenced and supported.');
            return Command::SUCCESS;
        }

        $activeUid = $this->versionRepository->findActive($fileUid, $sha1)?->uid;
        $profileFingerprint = $this->settings->createProfileSnapshot()->getFingerprint();
        $io->table(
            ['Version', 'Status', 'Content', 'Profile', 'Created', 'Completed', 'Details'],
            array_map(fn(Version $version): array => [
                $version->uid,
                $version->status->value . ($version->uid === $activeUid ? ' (active)' : ''),
                $version->sourceFingerprint === $sha1 ? 'current' : 'outdated',
                $version->profileFingerprint === $profileFingerprint ? 'current' : 'outdated',
                date(self::DATE_FORMAT, $version->createdAt),
                $version->completedAt > 0 ? date(self::DATE_FORMAT, $version->completedAt) : '',
                $version->status === VersionStatus::Ready
                    ? $this->artifactStorage->getPublicUrl($version->outputPrefix . '/' . FfmpegEncoder::MASTER_PLAYLIST)
                    : $version->errorMessage,
            ], $versions)
        );

        return Command::SUCCESS;
    }

    private function formatFile(int $fileUid): string
    {
        $file = $this->fileIndexRepository->findOneByUid($fileUid);

        return $file === false
            ? (string)$fileUid
            : sprintf('%d: %d:%s', $fileUid, $file['storage'], $file['identifier']);
    }
}
