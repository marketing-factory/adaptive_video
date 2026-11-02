<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Encodes and packages a source as CMAF/fMP4 HLS in a single ffmpeg run: the
 * source is decoded once, and all renditions share the keyframes and thus the
 * segment boundaries.
 *
 * Output layout below the working directory:
 * master.m3u8, <rendition>/{playlist.m3u8,init_<n>.mp4,segment_<n>.m4s} and
 * audio/… for the shared audio rendition.
 */
class FfmpegEncoder
{
    public const MASTER_PLAYLIST = 'master.m3u8';
    public const VARIANT_PLAYLIST = 'playlist.m3u8';
    public const AUDIO_NAME = 'audio';

    private const MAX_ERROR_OUTPUT_LENGTH = 1000;

    public function __construct(
        private readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * @param list<Rendition> $renditions
     */
    public function encode(string $sourcePath, SourceInfo $source, array $renditions, string $outputDirectory): void
    {
        $process = new Process(
            $this->buildCommand($sourcePath, $source, $renditions),
            $outputDirectory,
            timeout: $this->settings->maxRuntime
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new ProcessingException(
                sprintf('ffmpeg exceeded the maximum runtime of %d seconds.', $this->settings->maxRuntime),
                1793000050,
                $e
            );
        }

        if (!$process->isSuccessful()) {
            throw new ProcessingException(
                sprintf(
                    'ffmpeg failed (exit code %s): %s',
                    $process->getExitCode() ?? 'none',
                    mb_substr(trim($process->getErrorOutput()), -self::MAX_ERROR_OUTPUT_LENGTH)
                ),
                1793000051
            );
        }
    }

    /**
     * Arguments for a run in the output directory. Everything that concerns
     * input, output, codecs and segmentation is generated here; the configured
     * ffmpeg options are placed before the output and cannot replace it.
     *
     * @param list<Rendition> $renditions
     * @return list<string>
     */
    public function buildCommand(string $sourcePath, SourceInfo $source, array $renditions): array
    {
        $profile = $this->settings->profile;
        $frameRate = $profile->getFrameRateFor($source);
        $segmentDuration = $profile->segmentDuration;
        $keyframeInterval = (string)(int)ceil($frameRate * $segmentDuration);
        $hasAudio = $source->audioStreamIndex !== null;

        $command = [
            ...$this->settings->commandPrefix,
            $this->settings->ffmpegPath,
            '-hide_banner',
            '-nostdin',
            '-y',
            '-v', 'error',
            // The "file:" protocol prevents names like "concat:…" from being interpreted
            '-i', 'file:' . $sourcePath,
            '-filter_complex', $this->buildFilter($source->videoStreamIndex, $frameRate, $renditions),
        ];

        foreach (array_keys($renditions) as $index) {
            $command = [...$command, '-map', '[v' . $index . ']'];
        }

        if ($hasAudio) {
            $command = [...$command, '-map', '0:' . $source->audioStreamIndex];
        }

        $command = [
            ...$command,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-profile:v', 'high',
            // Fixed GOP without scene cut keyframes: identical segment boundaries in all renditions
            '-g', $keyframeInterval,
            '-keyint_min', $keyframeInterval,
            '-sc_threshold', '0',
            '-force_key_frames', 'expr:gte(t,n_forced*' . $segmentDuration . ')',
        ];

        foreach ($renditions as $index => $rendition) {
            $command = [
                ...$command,
                '-b:v:' . $index, $rendition->videoBitrate . 'k',
                '-maxrate:v:' . $index, (int)round($rendition->videoBitrate * 1.1) . 'k',
                '-bufsize:v:' . $index, ($rendition->videoBitrate * 2) . 'k',
            ];
        }

        if ($hasAudio) {
            $command = [
                ...$command,
                '-c:a', 'aac',
                '-b:a', $profile->audioBitrate . 'k',
                '-ar', (string)$profile->audioSampleRate,
                '-ac', '2',
            ];
        }

        return [
            ...$command,
            // As output options, so they apply to all encoders
            ...$this->settings->ffmpegOptions,
            '-f', 'hls',
            '-hls_time', (string)$segmentDuration,
            '-hls_playlist_type', 'vod',
            '-hls_segment_type', 'fmp4',
            '-hls_flags', 'independent_segments',
            '-hls_fmp4_init_filename', 'init.mp4',
            '-hls_segment_filename', '%v/segment_%05d.m4s',
            '-master_pl_name', self::MASTER_PLAYLIST,
            '-var_stream_map', $this->buildStreamMap($renditions, $hasAudio),
            '%v/' . self::VARIANT_PLAYLIST,
        ];
    }

    /**
     * @param list<Rendition> $renditions
     */
    private function buildFilter(int $videoStreamIndex, float $frameRate, array $renditions): string
    {
        $splitOutputs = '';
        $scaleFilters = [];
        foreach ($renditions as $index => $rendition) {
            $splitOutputs .= '[s' . $index . ']';
            $scaleFilters[] = sprintf('[s%d]scale=%d:%d[v%d]', $index, $rendition->width, $rendition->height, $index);
        }

        // Constant frame rate, so the fixed GOP size matches the segment duration
        return sprintf(
            '[0:%d]fps=%s,split=%d%s;%s',
            $videoStreamIndex,
            rtrim(rtrim(sprintf('%.3F', $frameRate), '0'), '.'),
            count($renditions),
            $splitOutputs,
            implode(';', $scaleFilters)
        );
    }

    /**
     * @param list<Rendition> $renditions
     */
    private function buildStreamMap(array $renditions, bool $hasAudio): string
    {
        $audioGroup = $hasAudio ? ',agroup:' . self::AUDIO_NAME : '';
        $streams = [];
        foreach ($renditions as $index => $rendition) {
            $streams[] = 'v:' . $index . $audioGroup . ',name:' . $rendition->name;
        }

        if ($hasAudio) {
            $streams[] = 'a:0' . $audioGroup . ',name:' . self::AUDIO_NAME . ',default:yes';
        }

        return implode(' ', $streams);
    }
}
