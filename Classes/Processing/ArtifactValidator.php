<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;

/**
 * Checks the encoder output before it is published (§7): playlists, all
 * referenced segments, aligned segment boundaries of the video renditions
 * and, via ffprobe, codec, resolution and duration.
 */
class ArtifactValidator
{
    /**
     * Maximum difference of segment durations between video renditions; less
     * than one frame at 30 fps.
     */
    private const ALIGNMENT_TOLERANCE = 0.02;

    public function __construct(
        private readonly SourceAnalyzer $sourceAnalyzer,
        private readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * @param list<Rendition> $renditions
     */
    public function validate(string $directory, array $renditions, SourceInfo $source): void
    {
        $hasAudio = $source->audioStreamIndex !== null;
        $this->validateMasterPlaylist($directory, $renditions, $hasAudio);

        $referenceDurations = null;
        foreach ($renditions as $rendition) {
            $durations = $this->validateVariantPlaylist($directory, $rendition->name);
            $referenceDurations ??= $durations;
            if (!$this->areAligned($referenceDurations, $durations)) {
                throw new ProcessingException(
                    sprintf('Segments of rendition %s are not aligned with rendition %s.', $rendition->name, $renditions[0]->name),
                    1793000063
                );
            }

            $this->validateVideoStream($directory, $rendition, $source);
        }

        if ($hasAudio) {
            $this->validateVariantPlaylist($directory, FfmpegEncoder::AUDIO_NAME);
            $this->validateAudioStream($directory);
        }
    }

    /**
     * @param list<Rendition> $renditions
     */
    private function validateMasterPlaylist(string $directory, array $renditions, bool $hasAudio): void
    {
        $lines = $this->readPlaylist($directory . '/' . FfmpegEncoder::MASTER_PLAYLIST);
        $expected = array_map(
            static fn(Rendition $rendition): string => $rendition->name . '/' . FfmpegEncoder::VARIANT_PLAYLIST,
            $renditions
        );
        $missing = array_diff($expected, $lines);
        if ($missing !== []) {
            throw new ProcessingException(
                sprintf('Master playlist does not reference %s.', implode(', ', $missing)),
                1793000061
            );
        }

        $audioUri = 'URI="' . FfmpegEncoder::AUDIO_NAME . '/' . FfmpegEncoder::VARIANT_PLAYLIST . '"';
        $audioLines = array_filter($lines, static fn(string $line): bool => str_contains($line, $audioUri));
        if ($hasAudio && $audioLines === []) {
            throw new ProcessingException('Master playlist does not reference the audio rendition.', 1793000061);
        }
    }

    /**
     * Checks a complete VOD playlist whose init and media segments all exist.
     *
     * @return list<float> segment durations
     */
    private function validateVariantPlaylist(string $directory, string $name): array
    {
        $variantDirectory = $directory . '/' . $name;
        $lines = $this->readPlaylist($variantDirectory . '/' . FfmpegEncoder::VARIANT_PLAYLIST);
        if (!in_array('#EXT-X-ENDLIST', $lines, true)) {
            throw new ProcessingException(sprintf('Playlist of %s is incomplete.', $name), 1793000062);
        }

        $files = [];
        $durations = [];
        foreach ($lines as $line) {
            if (preg_match('/^#EXT-X-MAP:URI="([^"]+)"/', $line, $matches) === 1) {
                $files[] = $matches[1];
            } elseif (preg_match('/^#EXTINF:([\d.]+)/', $line, $matches) === 1) {
                $durations[] = (float)$matches[1];
            } elseif ($line !== '' && !str_starts_with($line, '#')) {
                $files[] = $line;
            }
        }

        if ($durations === [] || count($files) !== count($durations) + 1) {
            throw new ProcessingException(sprintf('Playlist of %s has no init segment or no media segments.', $name), 1793000062);
        }

        foreach ($files as $file) {
            // Only plain file names: nothing may point outside the version directory
            if (basename($file) !== $file || !is_file($variantDirectory . '/' . $file) || filesize($variantDirectory . '/' . $file) === 0) {
                throw new ProcessingException(sprintf('Segment %s/%s is missing or empty.', $name, $file), 1793000062);
            }
        }

        return $durations;
    }

    private function validateVideoStream(string $directory, Rendition $rendition, SourceInfo $source): void
    {
        $data = $this->sourceAnalyzer->probe($directory . '/' . $rendition->name . '/' . FfmpegEncoder::VARIANT_PLAYLIST);
        $stream = $this->findStream($data, 'video');
        if (($stream['codec_name'] ?? '') !== 'h264'
            || (int)($stream['width'] ?? 0) !== $rendition->width
            || (int)($stream['height'] ?? 0) !== $rendition->height
        ) {
            throw new ProcessingException(
                sprintf(
                    'Rendition %s is %s %dx%d instead of h264 %dx%d.',
                    $rendition->name,
                    $stream['codec_name'] ?? 'unknown',
                    (int)($stream['width'] ?? 0),
                    (int)($stream['height'] ?? 0),
                    $rendition->width,
                    $rendition->height
                ),
                1793000064
            );
        }

        // Tolerant enough for sources whose audio is longer than the video
        $duration = (float)($data['format']['duration'] ?? 0);
        $tolerance = max((float)$this->settings->profile->segmentDuration, $source->duration * 0.01);
        if (abs($duration - $source->duration) > $tolerance) {
            throw new ProcessingException(
                sprintf('Rendition %s is %.1f seconds long instead of %.1f seconds.', $rendition->name, $duration, $source->duration),
                1793000065
            );
        }
    }

    private function validateAudioStream(string $directory): void
    {
        $data = $this->sourceAnalyzer->probe($directory . '/' . FfmpegEncoder::AUDIO_NAME . '/' . FfmpegEncoder::VARIANT_PLAYLIST);
        if (($this->findStream($data, 'audio')['codec_name'] ?? '') !== 'aac') {
            throw new ProcessingException('Audio rendition is not AAC.', 1793000064);
        }
    }

    /**
     * @param list<float> $reference
     * @param list<float> $durations
     */
    private function areAligned(array $reference, array $durations): bool
    {
        if (count($reference) !== count($durations)) {
            return false;
        }

        foreach ($reference as $index => $duration) {
            if (abs($duration - $durations[$index]) > self::ALIGNMENT_TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function readPlaylist(string $path): array
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        $lines = $content === false ? [] : array_map('trim', explode("\n", $content));
        if (($lines[0] ?? '') !== '#EXTM3U') {
            throw new ProcessingException(sprintf('Playlist %s is missing or invalid.', basename(dirname($path)) . '/' . basename($path)), 1793000060);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function findStream(array $data, string $type): array
    {
        foreach ((array)($data['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? '') === $type) {
                return $stream;
            }
        }

        return [];
    }
}
