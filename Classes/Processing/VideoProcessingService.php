<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

use Mfd\AdaptiveVideo\Cache\CacheInvalidator;
use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Model\Version;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Mfd\AdaptiveVideo\Storage\ArtifactStorageInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Processes one version inside the messenger worker. Every outcome ends in a
 * final status; exceptions are not passed on, as the core does not retry
 * messages anyway (ADR-004).
 */
final readonly class VideoProcessingService
{
    public function __construct(
        private VersionRepository $versionRepository,
        private SourceAnalyzer $sourceAnalyzer,
        private FfmpegEncoder $encoder,
        private ArtifactValidator $validator,
        private ArtifactStorageInterface $artifactStorage,
        private FileIndexRepository $fileIndexRepository,
        private ResourceFactory $resourceFactory,
        private ExtensionSettings $settings,
        private CacheInvalidator $cacheInvalidator,
        private LoggerInterface $logger,
    ) {
    }

    public function process(int $versionUid): void
    {
        $version = $this->versionRepository->findByUid($versionUid);
        if ($version === null) {
            $this->logger->notice('Version {versionUid} does not exist anymore, skipping message.', ['versionUid' => $versionUid]);
            return;
        }

        // A redelivered message for a version that is finished or still running elsewhere
        if (!$this->versionRepository->claim($versionUid, $this->settings->maxRuntime)) {
            $this->logger->info('Version {versionUid} is not claimable, skipping message.', ['versionUid' => $versionUid]);
            return;
        }

        try {
            $this->processClaimedVersion($version);
        } catch (\Throwable $e) {
            $this->logger->error('Processing of version {versionUid} (file {fileUid}) failed: {message}', [
                'versionUid' => $versionUid,
                'fileUid' => $version->fileUid,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $this->versionRepository->markFailed($versionUid, $e->getMessage());
        }
    }

    private function processClaimedVersion(Version $version): void
    {
        $file = $this->loadFile($version->fileUid);
        $this->logger->info('Version {versionUid} (file {fileUid}) started: {identifier}', [
            'versionUid' => $version->uid,
            'fileUid' => $version->fileUid,
            'identifier' => $file->getIdentifier(),
        ]);
        if ($file->getSha1() !== $version->sourceFingerprint) {
            // The next scan creates a version for the new content
            $this->skip($version, 'Source file changed after the version was created.');
            return;
        }

        if ($this->settings->createProfileSnapshot()->getFingerprint() !== $version->profileFingerprint) {
            // Encoding with the current profile would not match the version; the next scan creates a new one
            $this->skip($version, 'Encoding profile changed after the version was created.');
            return;
        }

        $maxFileSize = $this->settings->maxSourceFileSize * 1024 * 1024;
        if ($maxFileSize > 0 && $file->getSize() > $maxFileSize) {
            $this->skip($version, sprintf('Source file is larger than %d MB.', $this->settings->maxSourceFileSize));
            return;
        }

        $localPath = $file->getForLocalProcessing(false);
        try {
            $source = $this->sourceAnalyzer->analyze($localPath);
            if ($source->duration < $this->settings->minimumDuration) {
                $this->skip($version, sprintf('Duration is below the minimum of %d seconds.', $this->settings->minimumDuration));
                return;
            }

            if ($this->settings->maxSourceDuration > 0 && $source->duration > $this->settings->maxSourceDuration) {
                $this->skip($version, sprintf('Duration exceeds the maximum of %d seconds.', $this->settings->maxSourceDuration));
                return;
            }

            $this->encodeAndPublish($version, $localPath, $source);
        } finally {
            // Non-local storages hand out a temporary copy
            if ($file->getStorage()->getDriverType() !== 'Local') {
                @unlink($localPath);
            }
        }
    }

    /**
     * Encodes in a working directory, validates the result and publishes it
     * under a new prefix. Only then the version becomes ready and thus active.
     */
    private function encodeAndPublish(Version $version, string $localPath, SourceInfo $source): void
    {
        $renditions = $this->settings->profile->getRenditionsFor($source);
        $workingDirectory = $this->settings->temporaryDirectory . '/version_' . $version->uid;
        // A previous attempt of this version may have been aborted
        GeneralUtility::rmdir($workingDirectory, true);
        GeneralUtility::mkdir_deep($workingDirectory);
        try {
            $this->assertFreeSpace($renditions, $source);
            $this->logger->info('Version {versionUid} (file {fileUid}): encoding {width}x{height}, {duration} s to {renditions}.', [
                'versionUid' => $version->uid,
                'fileUid' => $version->fileUid,
                'width' => $source->width,
                'height' => $source->height,
                'duration' => round($source->duration),
                'renditions' => $this->formatRenditions($renditions),
            ]);
            $this->encoder->encode($localPath, $source, $renditions, $workingDirectory);
            $this->validator->validate($workingDirectory, $renditions, $source);
            $outputPrefix = $version->fileUid . '/' . $version->uid;
            $this->artifactStorage->publish($workingDirectory, $outputPrefix);
        } finally {
            GeneralUtility::rmdir($workingDirectory, true);
        }

        if (!$this->versionRepository->markReady($version->uid, $outputPrefix)) {
            // Taken over by another worker after maxRuntime; that one finishes the version
            $this->logger->warning('Version {versionUid} (file {fileUid}) was published, but is no longer processed by this worker.', [
                'versionUid' => $version->uid,
                'fileUid' => $version->fileUid,
            ]);
            return;
        }

        $this->logger->info('Version {versionUid} (file {fileUid}) is ready with {renditions}.', [
            'versionUid' => $version->uid,
            'fileUid' => $version->fileUid,
            'renditions' => $this->formatRenditions($renditions),
        ]);
        $this->cacheInvalidator->flush($version->fileUid);
    }

    /**
     * @param list<Rendition> $renditions
     */
    private function formatRenditions(array $renditions): string
    {
        return implode(', ', array_map(static fn(Rendition $rendition): string => $rendition->name, $renditions));
    }

    /**
     * Rough estimate of the output from the target bitrates plus a margin.
     * Without the same filesystem, the artifact directory needs the space, too.
     *
     * @param list<Rendition> $renditions
     */
    private function assertFreeSpace(array $renditions, SourceInfo $source): void
    {
        $bitrate = $source->audioStreamIndex === null ? 0 : $this->settings->profile->audioBitrate;
        foreach ($renditions as $rendition) {
            $bitrate += $rendition->videoBitrate;
        }

        $requiredBytes = (int)ceil($source->duration * $bitrate * 1000 / 8 * 1.5);
        $directories = array_unique([$this->settings->temporaryDirectory, $this->settings->getAbsoluteArtifactDirectory()]);
        foreach ($directories as $directory) {
            $freeBytes = is_dir($directory) ? @disk_free_space($directory) : false;
            if ($freeBytes !== false && $freeBytes < $requiredBytes) {
                throw new ProcessingException(
                    sprintf('Not enough disk space in %s: %d MB required, %d MB free.', $directory, $requiredBytes / 1048576, $freeBytes / 1048576),
                    1793000043
                );
            }
        }
    }

    /**
     * Reads the file record from the database instead of the ResourceFactory
     * cache: the worker runs long, and the file may have been replaced since.
     */
    private function loadFile(int $fileUid): File
    {
        $fileData = $this->fileIndexRepository->findOneByUid($fileUid);
        if ($fileData === false) {
            throw new ProcessingException(sprintf('File %d does not exist.', $fileUid), 1793000041);
        }

        $file = $this->resourceFactory->createFileObject($fileData);
        if ($file->isMissing()) {
            throw new ProcessingException(sprintf('File %d is marked as missing.', $fileUid), 1793000042);
        }

        return $file;
    }

    private function skip(Version $version, string $reason): void
    {
        $this->logger->info('Version {versionUid} (file {fileUid}) skipped: {reason}', [
            'versionUid' => $version->uid,
            'fileUid' => $version->fileUid,
            'reason' => $reason,
        ]);
        $this->versionRepository->markSkipped($version->uid, $reason);
    }
}
