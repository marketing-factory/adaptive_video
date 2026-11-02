<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;

/**
 * Properties of a source video as reported by ffprobe. Width and height are
 * the display dimensions, i.e. already swapped for videos rotated by 90°.
 */
final readonly class SourceInfo
{
    private function __construct(
        public float $duration,
        public int $width,
        public int $height,
        public float $frameRate,
        public int $videoStreamIndex,
        public ?int $audioStreamIndex,
    ) {
    }

    /**
     * @param array<string, mixed> $data decoded output of
     *        "ffprobe -print_format json -show_format -show_streams"
     */
    public static function fromFfprobeData(array $data): self
    {
        $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $videoStream = self::findVideoStream($streams);
        if ($videoStream === null) {
            throw new ProcessingException('Source contains no video stream.', 1793000030);
        }

        $width = (int)($videoStream['width'] ?? 0);
        $height = (int)($videoStream['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            throw new ProcessingException('Source video has no valid dimensions.', 1793000031);
        }

        if (abs(self::getRotation($videoStream)) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = (float)($data['format']['duration'] ?? $videoStream['duration'] ?? 0);
        if ($duration <= 0) {
            throw new ProcessingException('Source video has no valid duration.', 1793000032);
        }

        return new self(
            $duration,
            $width,
            $height,
            self::parseFrameRate((string)($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? '')),
            (int)$videoStream['index'],
            self::findAudioStreamIndex($streams),
        );
    }

    /**
     * First real video stream; embedded cover images are video streams, too.
     *
     * @param array<mixed> $streams
     * @return array<string, mixed>|null
     */
    private static function findVideoStream(array $streams): ?array
    {
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? '') === 'video' && (int)($stream['disposition']['attached_pic'] ?? 0) === 0) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * The audio stream marked as default, otherwise the first one.
     *
     * @param array<mixed> $streams
     */
    private static function findAudioStreamIndex(array $streams): ?int
    {
        $first = null;
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? '') !== 'audio') {
                continue;
            }

            if ((int)($stream['disposition']['default'] ?? 0) === 1) {
                return (int)$stream['index'];
            }

            $first ??= (int)$stream['index'];
        }

        return $first;
    }

    /**
     * Newer ffprobe versions report the display matrix rotation as side data,
     * older ones as "rotate" tag.
     *
     * @param array<string, mixed> $stream
     */
    private static function getRotation(array $stream): int
    {
        foreach ((array)($stream['side_data_list'] ?? []) as $sideData) {
            if (isset($sideData['rotation'])) {
                return (int)$sideData['rotation'];
            }
        }

        return (int)($stream['tags']['rotate'] ?? 0);
    }

    private static function parseFrameRate(string $frameRate): float
    {
        if (preg_match('#^(\d+)/(\d+)$#', $frameRate, $matches) === 1) {
            return (int)$matches[2] === 0 ? 0.0 : (int)$matches[1] / (int)$matches[2];
        }

        return (float)$frameRate;
    }
}
