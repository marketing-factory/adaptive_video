<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Processing;

use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Mfd\AdaptiveVideo\Processing\SourceInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SourceInfoTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $videoOverrides
     * @param list<array<string, mixed>> $additionalStreams
     * @return array<string, mixed>
     */
    private function createFfprobeData(array $videoOverrides = [], array $additionalStreams = [], ?string $duration = '125.48'): array
    {
        $data = [
            'streams' => [
                $videoOverrides + [
                    'index' => 0,
                    'codec_type' => 'video',
                    'width' => 1920,
                    'height' => 1080,
                    'avg_frame_rate' => '30000/1001',
                    'disposition' => ['default' => 1, 'attached_pic' => 0],
                ],
                ...$additionalStreams,
            ],
            'format' => [],
        ];
        if ($duration !== null) {
            $data['format']['duration'] = $duration;
        }

        return $data;
    }

    public function testLandscapeVideoWithoutAudio(): void
    {
        $subject = SourceInfo::fromFfprobeData($this->createFfprobeData());

        self::assertSame(125.48, $subject->duration);
        self::assertSame(1920, $subject->width);
        self::assertSame(1080, $subject->height);
        self::assertEqualsWithDelta(29.97, $subject->frameRate, 0.01);
        self::assertSame(0, $subject->videoStreamIndex);
        self::assertNull($subject->audioStreamIndex);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, int}>
     */
    public static function rotationDataProvider(): iterable
    {
        yield 'side data -90' => [['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => -90]]], 1080, 1920];
        yield 'side data 270' => [['side_data_list' => [['rotation' => 270]]], 1080, 1920];
        yield 'side data 180' => [['side_data_list' => [['rotation' => 180]]], 1920, 1080];
        yield 'legacy rotate tag' => [['tags' => ['rotate' => '90']], 1080, 1920];
    }

    /**
     * @param array<string, mixed> $videoOverrides
     */
    #[DataProvider('rotationDataProvider')]
    public function testRotationSwapsDisplayDimensions(array $videoOverrides, int $expectedWidth, int $expectedHeight): void
    {
        $subject = SourceInfo::fromFfprobeData($this->createFfprobeData($videoOverrides));

        self::assertSame($expectedWidth, $subject->width);
        self::assertSame($expectedHeight, $subject->height);
    }

    public function testDefaultAudioStreamIsPreferred(): void
    {
        $subject = SourceInfo::fromFfprobeData($this->createFfprobeData([], [
            ['index' => 1, 'codec_type' => 'audio', 'disposition' => ['default' => 0]],
            ['index' => 2, 'codec_type' => 'audio', 'disposition' => ['default' => 1]],
        ]));

        self::assertSame(2, $subject->audioStreamIndex);
    }

    public function testFirstAudioStreamIsUsedWithoutDefault(): void
    {
        $subject = SourceInfo::fromFfprobeData($this->createFfprobeData([], [
            ['index' => 1, 'codec_type' => 'data'],
            ['index' => 2, 'codec_type' => 'audio', 'disposition' => ['default' => 0]],
            ['index' => 3, 'codec_type' => 'audio', 'disposition' => ['default' => 0]],
        ]));

        self::assertSame(2, $subject->audioStreamIndex);
    }

    public function testCoverImageIsNotTakenAsVideoStream(): void
    {
        $data = $this->createFfprobeData(['index' => 1]);
        array_unshift($data['streams'], [
            'index' => 0,
            'codec_type' => 'video',
            'width' => 600,
            'height' => 600,
            'disposition' => ['attached_pic' => 1],
        ]);

        $subject = SourceInfo::fromFfprobeData($data);

        self::assertSame(1, $subject->videoStreamIndex);
        self::assertSame(1920, $subject->width);
    }

    public function testStreamDurationIsUsedWithoutFormatDuration(): void
    {
        $subject = SourceInfo::fromFfprobeData($this->createFfprobeData(['duration' => '12.5'], [], null));

        self::assertSame(12.5, $subject->duration);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function invalidSourceDataProvider(): iterable
    {
        yield 'no streams' => [['format' => ['duration' => '10']], 1793000030];
        yield 'audio only' => [['streams' => [['index' => 0, 'codec_type' => 'audio']], 'format' => ['duration' => '10']], 1793000030];
        yield 'no dimensions' => [['streams' => [['index' => 0, 'codec_type' => 'video']], 'format' => ['duration' => '10']], 1793000031];
        yield 'no duration' => [['streams' => [['index' => 0, 'codec_type' => 'video', 'width' => 640, 'height' => 360]], 'format' => []], 1793000032];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidSourceDataProvider')]
    public function testInvalidSourceIsRejected(array $data, int $expectedCode): void
    {
        $this->expectException(ProcessingException::class);
        $this->expectExceptionCode($expectedCode);

        SourceInfo::fromFfprobeData($data);
    }
}
