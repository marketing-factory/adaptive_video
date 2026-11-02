<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

/**
 * One video variant to be produced for a concrete source.
 */
final readonly class Rendition
{
    /**
     * @param string $name directory and display name, e.g. "720p"
     * @param int $videoBitrate kbit/s
     */
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public int $videoBitrate,
    ) {
    }
}
