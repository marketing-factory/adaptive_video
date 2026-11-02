<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Configuration;

use Mfd\AdaptiveVideo\Processing\EncodingProfile;
use Mfd\AdaptiveVideo\Processing\ProfileSnapshot;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Validated global configuration (ext_conf_template.txt plus encoding profiles
 * from $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adaptive_video']['profiles']).
 */
final readonly class ExtensionSettings
{
    public const EXTENSION_KEY = 'adaptive_video';

    /**
     * @param list<string> $supportedFileExtensions
     * @param list<string> $commandPrefix
     * @param list<string> $ffmpegOptions
     */
    private function __construct(
        public bool $processingEnabled,
        public bool $deliveryEnabled,
        public EncodingProfile $profile,
        public array $supportedFileExtensions,
        public int $minimumDuration,
        public string $ffmpegPath,
        public string $ffprobePath,
        public array $commandPrefix,
        public array $ffmpegOptions,
        public int $maxRuntime,
        public int $redeliverTimeout,
        public int $maxSourceFileSize,
        public int $maxSourceDuration,
        public string $artifactDirectory,
        public string $publicUrlPrefix,
        public string $temporaryDirectory,
        public int $rendererPriority,
    ) {
    }

    public static function create(ExtensionConfiguration $extensionConfiguration): self
    {
        try {
            $configuration = (array)$extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (ExtensionConfigurationExtensionNotConfiguredException) {
            // Not yet synchronized from ext_conf_template.txt: use the defaults
            $configuration = [];
        }

        return self::fromArray(
            $configuration,
            (array)($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXTENSION_KEY]['profiles'] ?? [])
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $profiles additional or overridden profile definitions
     */
    public static function fromArray(array $configuration, array $profiles = []): self
    {
        $profiles += [EncodingProfile::DEFAULT_IDENTIFIER => EncodingProfile::DEFAULT_CONFIGURATION];
        $profileIdentifier = trim((string)($configuration['defaultProfile'] ?? '')) ?: EncodingProfile::DEFAULT_IDENTIFIER;
        if (!is_array($profiles[$profileIdentifier] ?? null)) {
            throw new \InvalidArgumentException(
                sprintf('Encoding profile "%s" is not defined.', $profileIdentifier),
                1793000020
            );
        }

        $maxRuntime = max(1, (int)($configuration['maxRuntime'] ?? 10800));
        $redeliverTimeout = max(1, (int)($configuration['redeliverTimeout'] ?? 14400));
        if ($maxRuntime >= $redeliverTimeout) {
            // Otherwise a running encoding would be delivered to a second worker
            throw new \InvalidArgumentException(
                sprintf('maxRuntime (%d s) must be lower than redeliverTimeout (%d s).', $maxRuntime, $redeliverTimeout),
                1793000023
            );
        }

        return new self(
            (bool)($configuration['processingEnabled'] ?? false),
            (bool)($configuration['deliveryEnabled'] ?? true),
            EncodingProfile::fromArray($profileIdentifier, $profiles[$profileIdentifier]),
            array_map('strtolower', GeneralUtility::trimExplode(',', (string)($configuration['supportedFileExtensions'] ?? 'mp4,mov,m4v,webm'), true)),
            max(0, (int)($configuration['minimumDuration'] ?? 10)),
            trim((string)($configuration['ffmpegPath'] ?? '')) ?: 'ffmpeg',
            trim((string)($configuration['ffprobePath'] ?? '')) ?: 'ffprobe',
            // Argument lists for Symfony Process: "a -b c" becomes ['a', '-b', 'c']
            GeneralUtility::trimExplode(' ', (string)($configuration['commandPrefix'] ?? ''), true),
            GeneralUtility::trimExplode(' ', (string)($configuration['ffmpegOptions'] ?? ''), true),
            $maxRuntime,
            $redeliverTimeout,
            max(0, (int)($configuration['maxSourceFileSize'] ?? 0)),
            max(0, (int)($configuration['maxSourceDuration'] ?? 0)),
            self::validateArtifactDirectory((string)($configuration['artifactDirectory'] ?? '')),
            self::normalizePublicUrlPrefix((string)($configuration['publicUrlPrefix'] ?? '')),
            self::resolveTemporaryDirectory((string)($configuration['temporaryDirectory'] ?? '')),
            max(1, (int)($configuration['rendererPriority'] ?? 20)),
        );
    }

    /**
     * Snapshot of the active profile; its fingerprint identifies versions.
     */
    public function createProfileSnapshot(): ProfileSnapshot
    {
        return ProfileSnapshot::create($this->profile, $this->ffmpegOptions);
    }

    public function isSupportedFileExtension(string $extension): bool
    {
        return in_array(strtolower($extension), $this->supportedFileExtensions, true);
    }

    /**
     * Absolute path of the artifact directory inside the public web root.
     */
    public function getAbsoluteArtifactDirectory(): string
    {
        return Environment::getPublicPath() . '/' . $this->artifactDirectory;
    }

    /**
     * The artifact directory must stay inside the public web root. A symlink
     * is allowed on purpose: deployments can keep the directory in a shared
     * location outside the release.
     */
    private static function validateArtifactDirectory(string $directory): string
    {
        $directory = trim(trim($directory), '/');
        if ($directory === '') {
            $directory = '_videos';
        }

        $segments = explode('/', $directory);
        if (in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)) {
            throw new \InvalidArgumentException(
                sprintf('Artifact directory "%s" must be a plain path relative to the public web root.', $directory),
                1793000021
            );
        }

        return $directory;
    }

    private static function normalizePublicUrlPrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return '/_videos/';
        }

        if (!str_starts_with($prefix, '/') || str_starts_with($prefix, '//')) {
            throw new \InvalidArgumentException(
                sprintf('Public URL prefix "%s" must be an absolute path without host, e.g. "/_videos/".', $prefix),
                1793000022
            );
        }

        return rtrim($prefix, '/') . '/';
    }

    private static function resolveTemporaryDirectory(string $directory): string
    {
        $directory = rtrim(trim($directory), '/');
        if ($directory === '') {
            return Environment::getVarPath() . '/transient/' . self::EXTENSION_KEY;
        }

        if (!str_starts_with($directory, '/')) {
            $directory = Environment::getProjectPath() . '/' . $directory;
        }

        return $directory;
    }
}
