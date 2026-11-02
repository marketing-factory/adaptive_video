<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Rendering;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Processing\FfmpegEncoder;
use Mfd\AdaptiveVideo\Storage\ArtifactStorageInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;

/**
 * Decides between stream and original for a file. Kept apart from the
 * renderer, so a later ViewHelper can use the same logic (§8).
 */
class StreamResolver
{
    /**
     * @var array<string, ?string> playlist URL per file uid and content
     */
    private array $playlistUrls = [];

    public function __construct(
        private readonly VersionRepository $versionRepository,
        private readonly ArtifactStorageInterface $artifactStorage,
        private readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * URL of the master playlist of the active version, or null to deliver the
     * original. The artifacts are deliberately not checked for existence.
     */
    public function getPlaylistUrl(FileInterface $file): ?string
    {
        $file = $this->getOriginalFile($file);
        if ($file === null || !$this->settings->isSupportedFileExtension($file->getExtension())) {
            return null;
        }

        // The same file often appears several times on a page (e.g. translations)
        $key = $file->getUid() . ':' . $file->getSha1();
        if (!array_key_exists($key, $this->playlistUrls)) {
            $version = $this->versionRepository->findActive($file->getUid(), $file->getSha1());
            $this->playlistUrls[$key] = $version === null
                ? null
                : $this->artifactStorage->getPublicUrl($version->outputPrefix . '/' . FfmpegEncoder::MASTER_PLAYLIST);
        }

        return $this->playlistUrls[$key];
    }

    public function getOriginalFile(FileInterface $file): ?File
    {
        if ($file instanceof FileReference) {
            $file = $file->getOriginalFile();
        }

        return $file instanceof File ? $file : null;
    }
}
