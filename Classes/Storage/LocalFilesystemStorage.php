<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Storage;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Symfony\Component\Filesystem\Filesystem;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Artifact directory in the public web root (default "_videos"). Publishing
 * renames the working directory, which is atomic on the same filesystem;
 * otherwise the files are copied next to the target first and then renamed.
 * The directory may be a symlink, e.g. a shared directory of the deployment.
 */
final readonly class LocalFilesystemStorage implements ArtifactStorageInterface
{
    private Filesystem $filesystem;

    public function __construct(
        private ExtensionSettings $settings,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function publish(string $localDirectory, string $prefix): void
    {
        $target = $this->getAbsolutePath($prefix);
        $parent = dirname($target);
        GeneralUtility::mkdir_deep($parent);
        // Left over by an attempt of the same version that was aborted before
        // it became ready; nothing references it
        $this->filesystem->remove($target);

        $source = $localDirectory;
        if (!$this->isSameFilesystem($localDirectory, $parent)) {
            $source = $parent . '/.' . basename($target) . '.' . bin2hex(random_bytes(4));
            $this->filesystem->mirror($localDirectory, $source);
        }

        $this->filesystem->rename($source, $target);
        // Files created by ffmpeg follow the umask of the worker
        GeneralUtility::fixPermissions($target, true);
        if ($source !== $localDirectory) {
            $this->filesystem->remove($localDirectory);
        }
    }

    public function delete(string $prefix): void
    {
        $this->filesystem->remove($this->getAbsolutePath($prefix));
    }

    public function getPublicUrl(string $path): string
    {
        return $this->settings->publicUrlPrefix . ltrim($path, '/');
    }

    private function getAbsolutePath(string $prefix): string
    {
        if (preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $prefix) !== 1) {
            throw new ProcessingException(sprintf('Invalid artifact prefix "%s".', $prefix), 1793000070);
        }

        return $this->settings->getAbsoluteArtifactDirectory() . '/' . $prefix;
    }

    private function isSameFilesystem(string $path, string $otherPath): bool
    {
        $stat = @stat($path);
        $otherStat = @stat($otherPath);

        return $stat !== false && $otherStat !== false && $stat['dev'] === $otherStat['dev'];
    }
}
