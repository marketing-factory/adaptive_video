<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

/**
 * Encoding parameters of one profile. Renditions are ordered from the highest
 * to the lowest target height; which of them are produced for a given source
 * (no upscaling) is decided at processing time.
 */
final readonly class EncodingProfile
{
    public const DEFAULT_IDENTIFIER = 'default';

    public const DEFAULT_CONFIGURATION = [
        'renditions' => [
            ['height' => 1080, 'videoBitrate' => 5000],
            ['height' => 720, 'videoBitrate' => 2800],
            ['height' => 480, 'videoBitrate' => 1400],
            ['height' => 360, 'videoBitrate' => 800],
        ],
        'audioBitrate' => 128,
        'audioSampleRate' => 48000,
        'maxFrameRate' => 30,
        'segmentDuration' => 4,
    ];

    /**
     * @param list<array{height: int, videoBitrate: int}> $renditions video bitrates in kbit/s
     */
    private function __construct(
        public string $identifier,
        public array $renditions,
        public int $audioBitrate,
        public int $audioSampleRate,
        public int $maxFrameRate,
        public int $segmentDuration,
    ) {
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function fromArray(string $identifier, array $configuration): self
    {
        $renditions = [];
        foreach ((array)($configuration['renditions'] ?? []) as $rendition) {
            $height = self::positiveInt($identifier, 'renditions.height', $rendition['height'] ?? null);
            if (isset($renditions[$height])) {
                throw new \InvalidArgumentException(
                    sprintf('Encoding profile "%s" defines the rendition height %d more than once.', $identifier, $height),
                    1793000001
                );
            }

            $renditions[$height] = [
                'height' => $height,
                'videoBitrate' => self::positiveInt($identifier, 'renditions.videoBitrate', $rendition['videoBitrate'] ?? null),
            ];
        }

        if ($renditions === []) {
            throw new \InvalidArgumentException(
                sprintf('Encoding profile "%s" defines no renditions.', $identifier),
                1793000002
            );
        }

        krsort($renditions);

        return new self(
            $identifier,
            array_values($renditions),
            self::positiveInt($identifier, 'audioBitrate', $configuration['audioBitrate'] ?? null),
            self::positiveInt($identifier, 'audioSampleRate', $configuration['audioSampleRate'] ?? null),
            self::positiveInt($identifier, 'maxFrameRate', $configuration['maxFrameRate'] ?? null),
            self::positiveInt($identifier, 'segmentDuration', $configuration['segmentDuration'] ?? null),
        );
    }

    /**
     * Renditions for a source without upscaling. The configured
     * height refers to the shorter side, so a portrait video gets 1080x1920 as
     * "1080p". A source below the smallest rendition is encoded once in its
     * own size with the smallest bitrate.
     *
     * @return list<Rendition>
     */
    public function getRenditionsFor(SourceInfo $source): array
    {
        $shortSide = min($source->width, $source->height);
        $renditions = [];
        foreach ($this->renditions as $rendition) {
            if ($rendition['height'] <= $shortSide) {
                $renditions[] = $this->createRendition($source, $rendition['height'], $rendition['videoBitrate']);
            }
        }

        if ($renditions === []) {
            $smallest = $this->renditions[array_key_last($this->renditions)];
            $renditions[] = $this->createRendition($source, $shortSide, $smallest['videoBitrate']);
        }

        return $renditions;
    }

    /**
     * Frame rate of the output: the source frame rate, limited by the profile.
     */
    public function getFrameRateFor(SourceInfo $source): float
    {
        return $source->frameRate > 0 ? min($source->frameRate, (float)$this->maxFrameRate) : (float)$this->maxFrameRate;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'renditions' => $this->renditions,
            'audioBitrate' => $this->audioBitrate,
            'audioSampleRate' => $this->audioSampleRate,
            'maxFrameRate' => $this->maxFrameRate,
            'segmentDuration' => $this->segmentDuration,
        ];
    }

    private function createRendition(SourceInfo $source, int $shortSide, int $videoBitrate): Rendition
    {
        $scale = $shortSide / min($source->width, $source->height);

        // H.264 with 4:2:0 chroma subsampling needs even dimensions
        return new Rendition(
            $shortSide . 'p',
            self::even($source->width * $scale, $source->width),
            self::even($source->height * $scale, $source->height),
            $videoBitrate
        );
    }

    /**
     * Nearest even number that does not exceed the source dimension.
     */
    private static function even(float $value, int $sourceDimension): int
    {
        $even = (int)round($value / 2) * 2;
        if ($even > $sourceDimension) {
            $even -= 2;
        }

        return max(2, $even);
    }

    private static function positiveInt(string $identifier, string $key, mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException(
                sprintf('Encoding profile "%s": "%s" must be a positive integer.', $identifier, $key),
                1793000003
            );
        }

        $value = (int)$value;
        if ($value <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Encoding profile "%s": "%s" must be a positive integer.', $identifier, $key),
                1793000003
            );
        }

        return $value;
    }
}
