<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Processing;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\FfmpegEncoder;
use Mfd\AdaptiveVideo\Processing\Rendition;
use Mfd\AdaptiveVideo\Processing\SourceInfo;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FfmpegEncoderTest extends UnitTestCase
{
    /**
     * @param array<string, string> $configuration
     * @return list<string>
     */
    private function buildCommand(?int $audioStreamIndex = 1, float $frameRate = 25.0, array $configuration = []): array
    {
        $streams = [['index' => 0, 'codec_type' => 'video', 'width' => 1280, 'height' => 720, 'avg_frame_rate' => (string)$frameRate]];
        if ($audioStreamIndex !== null) {
            $streams[] = ['index' => $audioStreamIndex, 'codec_type' => 'audio'];
        }

        $source = SourceInfo::fromFfprobeData(['streams' => $streams, 'format' => ['duration' => '60']]);
        $subject = new FfmpegEncoder(ExtensionSettings::fromArray($configuration + ['commandPrefix' => 'nice -n 19', 'ffmpegOptions' => '-threads 1']));

        return $subject->buildCommand('/var/www/fileadmin/a b.mp4', $source, [
            new Rendition('720p', 1280, 720, 2800),
            new Rendition('480p', 854, 480, 1400),
        ]);
    }

    /**
     * @param list<string> $command
     */
    private function getOption(array $command, string $option): string
    {
        $index = array_search($option, $command, true);
        self::assertIsInt($index, sprintf('Option %s is missing.', $option));

        return $command[$index + 1];
    }

    public function testCommandStartsWithPrefixBinaryAndProtectedInput(): void
    {
        $command = $this->buildCommand();

        self::assertSame(['nice', '-n', '19', 'ffmpeg'], array_slice($command, 0, 4));
        self::assertSame('file:/var/www/fileadmin/a b.mp4', $this->getOption($command, '-i'));
    }

    public function testOneFilterGraphProducesAllRenditions(): void
    {
        self::assertSame(
            '[0:0]fps=25,split=2[s0][s1];[s0]scale=1280:720[v0];[s1]scale=854:480[v1]',
            $this->getOption($this->buildCommand(), '-filter_complex')
        );
    }

    public function testFrameRateIsLimitedAndKeyframesMatchTheSegmentDuration(): void
    {
        $command = $this->buildCommand(frameRate: 59.94);

        self::assertStringContainsString('fps=30,', $this->getOption($command, '-filter_complex'));
        self::assertSame('120', $this->getOption($command, '-g'));
        self::assertSame('120', $this->getOption($command, '-keyint_min'));
        self::assertSame('0', $this->getOption($command, '-sc_threshold'));
        self::assertSame('expr:gte(t,n_forced*4)', $this->getOption($command, '-force_key_frames'));
        self::assertSame('4', $this->getOption($command, '-hls_time'));
    }

    public function testFractionalFrameRateIsKept(): void
    {
        self::assertStringContainsString('fps=29.97,', $this->getOption($this->buildCommand(frameRate: 29.97), '-filter_complex'));
    }

    public function testBitratesArePerRendition(): void
    {
        $command = $this->buildCommand();

        self::assertSame('2800k', $this->getOption($command, '-b:v:0'));
        self::assertSame('3080k', $this->getOption($command, '-maxrate:v:0'));
        self::assertSame('5600k', $this->getOption($command, '-bufsize:v:0'));
        self::assertSame('1400k', $this->getOption($command, '-b:v:1'));
    }

    public function testAudioIsASharedRendition(): void
    {
        $command = $this->buildCommand(audioStreamIndex: 2);

        self::assertContains('0:2', $command);
        self::assertSame('aac', $this->getOption($command, '-c:a'));
        self::assertSame('128k', $this->getOption($command, '-b:a'));
        self::assertSame('48000', $this->getOption($command, '-ar'));
        self::assertSame(
            'v:0,agroup:audio,name:720p v:1,agroup:audio,name:480p a:0,agroup:audio,name:audio,default:yes',
            $this->getOption($command, '-var_stream_map')
        );
    }

    public function testSilentSourceGetsNoAudioRendition(): void
    {
        $command = $this->buildCommand(audioStreamIndex: null);

        self::assertNotContains('-c:a', $command);
        self::assertSame('v:0,name:720p v:1,name:480p', $this->getOption($command, '-var_stream_map'));
    }

    public function testConfiguredOptionsComeBeforeTheGeneratedOutput(): void
    {
        $command = $this->buildCommand(configuration: ['ffmpegOptions' => '-threads 2 -preset slow']);

        $threads = array_search('-threads', $command, true);
        self::assertSame(['-threads', '2', '-preset', 'slow', '-f', 'hls'], array_slice($command, (int)$threads, 6));
        self::assertSame('%v/playlist.m3u8', $command[array_key_last($command)]);
    }

    public function testCommandWithoutPrefix(): void
    {
        self::assertSame('ffmpeg', $this->buildCommand(configuration: ['commandPrefix' => ''])[0]);
    }
}
