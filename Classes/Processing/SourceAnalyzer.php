<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Symfony\Component\Process\Process;

/**
 * Reads the properties of local video files and playlists with ffprobe.
 */
class SourceAnalyzer
{
    private const TIMEOUT = 120;

    public function __construct(
        private readonly ExtensionSettings $settings,
    ) {
    }

    public function analyze(string $localPath): SourceInfo
    {
        return SourceInfo::fromFfprobeData($this->probe($localPath));
    }

    /**
     * Raw ffprobe data of a media file or HLS playlist.
     *
     * @return array<string, mixed>
     */
    public function probe(string $localPath): array
    {
        $process = new Process($this->buildCommand($localPath), timeout: self::TIMEOUT);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ProcessingException(
                sprintf('ffprobe failed (exit code %s): %s', $process->getExitCode() ?? 'none', trim($process->getErrorOutput())),
                1793000033
            );
        }

        try {
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProcessingException('ffprobe returned invalid JSON: ' . $e->getMessage(), 1793000034, $e);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<string>
     */
    public function buildCommand(string $localPath): array
    {
        return [
            ...$this->settings->commandPrefix,
            $this->settings->ffprobePath,
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            // The "file:" protocol prevents names like "concat:…" from being interpreted
            'file:' . $localPath,
        ];
    }
}
