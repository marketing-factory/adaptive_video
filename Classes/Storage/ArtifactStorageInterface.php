<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Storage;

/**
 * Storage for generated streams, deliberately outside FAL (ADR-015). Physical
 * location and public URL are kept separate (ADR-003).
 */
interface ArtifactStorageInterface
{
    /**
     * Moves a complete local directory to the given version prefix. An existing
     * directory under that prefix is replaced.
     */
    public function publish(string $localDirectory, string $prefix): void;

    public function delete(string $prefix): void;

    /**
     * @param string $path relative to the artifact directory, e.g. "<prefix>/master.m3u8"
     */
    public function getPublicUrl(string $path): string;
}
