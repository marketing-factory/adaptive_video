<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Processing;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\ArtifactValidator;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Mfd\AdaptiveVideo\Processing\Rendition;
use Mfd\AdaptiveVideo\Processing\SourceAnalyzer;
use Mfd\AdaptiveVideo\Processing\SourceInfo;
use Symfony\Component\Filesystem\Filesystem;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ArtifactValidatorTest extends UnitTestCase
{
    private const MASTER_PLAYLIST = <<<'M3U8'
        #EXTM3U
        #EXT-X-VERSION:7
        #EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="group_audio",NAME="audio_2",DEFAULT=YES,CHANNELS="2",URI="audio/playlist.m3u8"
        #EXT-X-STREAM-INF:BANDWIDTH=2817608,RESOLUTION=1280x720,CODECS="avc1.64001f,mp4a.40.2",AUDIO="group_audio"
        720p/playlist.m3u8

        #EXT-X-STREAM-INF:BANDWIDTH=1412470,RESOLUTION=854x480,CODECS="avc1.64001f,mp4a.40.2",AUDIO="group_audio"
        480p/playlist.m3u8

        M3U8;

    private string $directory;

    /**
     * @var array<string, array{0: int, 1: int}> probed dimensions per rendition
     */
    private array $probedDimensions = ['720p' => [1280, 720], '480p' => [854, 480]];

    private float $probedDuration = 12.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/adaptive_video_test_' . bin2hex(random_bytes(4));
        file_put_contents($this->createDirectory('') . '/master.m3u8', self::MASTER_PLAYLIST);
        $this->writeVariant('720p', 0, [4.0, 4.0, 4.0]);
        $this->writeVariant('480p', 1, [4.0, 4.0, 4.0]);
        $this->writeVariant('audio', 2, [4.010667, 3.989333, 4.010667, 0.021333]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    private function createDirectory(string $name): string
    {
        $directory = rtrim($this->directory . '/' . $name, '/');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory;
    }

    /**
     * @param list<float> $durations
     */
    private function writeVariant(string $name, int $index, array $durations): void
    {
        $directory = $this->createDirectory($name);
        $lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-PLAYLIST-TYPE:VOD', '#EXT-X-MAP:URI="init_' . $index . '.mp4"'];
        file_put_contents($directory . '/init_' . $index . '.mp4', 'init');
        foreach ($durations as $segment => $duration) {
            $file = sprintf('segment_%05d.m4s', $segment);
            $lines[] = sprintf('#EXTINF:%F,', $duration);
            $lines[] = $file;
            file_put_contents($directory . '/' . $file, 'data');
        }

        $lines[] = '#EXT-X-ENDLIST';
        file_put_contents($directory . '/playlist.m3u8', implode("\n", $lines) . "\n");
    }

    private function createSource(bool $withAudio = true): SourceInfo
    {
        $streams = [['index' => 0, 'codec_type' => 'video', 'width' => 1920, 'height' => 1080]];
        if ($withAudio) {
            $streams[] = ['index' => 1, 'codec_type' => 'audio'];
        }

        return SourceInfo::fromFfprobeData(['streams' => $streams, 'format' => ['duration' => '12.03']]);
    }

    /**
     * @return list<Rendition>
     */
    private function createRenditions(): array
    {
        return [new Rendition('720p', 1280, 720, 2800), new Rendition('480p', 854, 480, 1400)];
    }

    private function validate(?SourceInfo $source = null): void
    {
        $sourceAnalyzer = $this->createStub(SourceAnalyzer::class);
        $sourceAnalyzer->method('probe')->willReturnCallback(function (string $path): array {
            $name = basename(dirname($path));
            if ($name === 'audio') {
                return ['streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']], 'format' => ['duration' => '12.03']];
            }

            [$width, $height] = $this->probedDimensions[$name];

            return [
                'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => $width, 'height' => $height]],
                'format' => ['duration' => (string)$this->probedDuration],
            ];
        });

        (new ArtifactValidator($sourceAnalyzer, ExtensionSettings::fromArray([])))
            ->validate($this->directory, $this->createRenditions(), $source ?? $this->createSource());
    }

    private function expectProcessingException(int $code): void
    {
        $this->expectException(ProcessingException::class);
        $this->expectExceptionCode($code);
    }

    public function testCompleteOutputIsValid(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validate();
    }

    public function testMissingMasterPlaylistIsRejected(): void
    {
        unlink($this->directory . '/master.m3u8');
        $this->expectProcessingException(1793000060);

        $this->validate();
    }

    public function testMasterPlaylistMustReferenceAllRenditions(): void
    {
        file_put_contents($this->directory . '/master.m3u8', str_replace('480p/playlist.m3u8', '', self::MASTER_PLAYLIST));
        $this->expectProcessingException(1793000061);

        $this->validate();
    }

    public function testMasterPlaylistMustReferenceTheAudioRendition(): void
    {
        file_put_contents($this->directory . '/master.m3u8', str_replace('URI="audio/playlist.m3u8"', '', self::MASTER_PLAYLIST));
        $this->expectProcessingException(1793000061);

        $this->validate();
    }

    public function testSilentSourceNeedsNoAudioRendition(): void
    {
        (new Filesystem())->remove($this->directory . '/audio');
        $this->expectNotToPerformAssertions();

        $this->validate($this->createSource(withAudio: false));
    }

    public function testIncompletePlaylistIsRejected(): void
    {
        $path = $this->directory . '/480p/playlist.m3u8';
        file_put_contents($path, str_replace('#EXT-X-ENDLIST', '', (string)file_get_contents($path)));
        $this->expectProcessingException(1793000062);

        $this->validate();
    }

    public function testMissingSegmentIsRejected(): void
    {
        unlink($this->directory . '/720p/segment_00001.m4s');
        $this->expectProcessingException(1793000062);

        $this->validate();
    }

    public function testMissingInitSegmentIsRejected(): void
    {
        unlink($this->directory . '/audio/init_2.mp4');
        $this->expectProcessingException(1793000062);

        $this->validate();
    }

    public function testSegmentOutsideTheVariantDirectoryIsRejected(): void
    {
        $path = $this->directory . '/720p/playlist.m3u8';
        file_put_contents($path, str_replace('segment_00000.m4s', '../480p/segment_00000.m4s', (string)file_get_contents($path)));
        $this->expectProcessingException(1793000062);

        $this->validate();
    }

    public function testMisalignedSegmentsAreRejected(): void
    {
        $this->writeVariant('480p', 1, [4.0, 3.5, 4.5]);
        $this->expectProcessingException(1793000063);

        $this->validate();
    }

    public function testWrongResolutionIsRejected(): void
    {
        $this->probedDimensions['480p'] = [852, 480];
        $this->expectProcessingException(1793000064);

        $this->validate();
    }

    public function testTruncatedOutputIsRejected(): void
    {
        $this->probedDuration = 6.0;
        $this->expectProcessingException(1793000065);

        $this->validate();
    }
}
