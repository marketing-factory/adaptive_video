<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Processing;

use Mfd\AdaptiveVideo\Processing\EncodingProfile;
use Mfd\AdaptiveVideo\Processing\Rendition;
use Mfd\AdaptiveVideo\Processing\SourceInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EncodingProfileTest extends UnitTestCase
{
    public function testDefaultConfigurationIsValid(): void
    {
        $subject = EncodingProfile::fromArray('default', EncodingProfile::DEFAULT_CONFIGURATION);

        self::assertSame([1080, 720, 480, 360], array_column($subject->renditions, 'height'));
        self::assertSame(128, $subject->audioBitrate);
        self::assertSame(48000, $subject->audioSampleRate);
        self::assertSame(30, $subject->maxFrameRate);
        self::assertSame(4, $subject->segmentDuration);
    }

    public function testRenditionsAreOrderedByHeightDescending(): void
    {
        $configuration = EncodingProfile::DEFAULT_CONFIGURATION;
        $configuration['renditions'] = [
            ['height' => 360, 'videoBitrate' => 800],
            ['height' => 1080, 'videoBitrate' => 5000],
            ['height' => 720, 'videoBitrate' => 2800],
        ];

        $subject = EncodingProfile::fromArray('custom', $configuration);

        self::assertSame([1080, 720, 360], array_column($subject->renditions, 'height'));
    }

    public function testNumericStringsFromConfigurationFilesAreAccepted(): void
    {
        $configuration = EncodingProfile::DEFAULT_CONFIGURATION;
        $configuration['renditions'] = [['height' => '720', 'videoBitrate' => '2800']];
        $configuration['segmentDuration'] = '6';

        $subject = EncodingProfile::fromArray('custom', $configuration);

        self::assertSame([['height' => 720, 'videoBitrate' => 2800]], $subject->renditions);
        self::assertSame(6, $subject->segmentDuration);
    }

    public function testToArrayRoundTrips(): void
    {
        $subject = EncodingProfile::fromArray('default', EncodingProfile::DEFAULT_CONFIGURATION);

        self::assertEquals($subject, EncodingProfile::fromArray('default', $subject->toArray()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function invalidConfigurationDataProvider(): iterable
    {
        $valid = EncodingProfile::DEFAULT_CONFIGURATION;

        yield 'no renditions' => [['renditions' => []] + $valid, 1793000002];
        yield 'duplicate height' => [
            ['renditions' => [['height' => 720, 'videoBitrate' => 2800], ['height' => 720, 'videoBitrate' => 1400]]] + $valid,
            1793000001,
        ];
        yield 'missing bitrate' => [['renditions' => [['height' => 720]]] + $valid, 1793000003];
        yield 'zero height' => [['renditions' => [['height' => 0, 'videoBitrate' => 800]]] + $valid, 1793000003];
        yield 'negative segment duration' => [['segmentDuration' => -4] + $valid, 1793000003];
        yield 'float frame rate' => [['maxFrameRate' => 29.97] + $valid, 1793000003];
        yield 'missing audio bitrate' => [array_diff_key($valid, ['audioBitrate' => true]), 1793000003];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('invalidConfigurationDataProvider')]
    public function testInvalidConfigurationIsRejected(array $configuration, int $expectedCode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode($expectedCode);

        EncodingProfile::fromArray('broken', $configuration);
    }

    private function createSource(int $width, int $height, float $frameRate = 25.0): SourceInfo
    {
        return SourceInfo::fromFfprobeData([
            'streams' => [[
                'index' => 0,
                'codec_type' => 'video',
                'width' => $width,
                'height' => $height,
                'avg_frame_rate' => (string)$frameRate,
            ]],
            'format' => ['duration' => '60'],
        ]);
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: int}>
     */
    private function describe(SourceInfo $source): array
    {
        $subject = EncodingProfile::fromArray('default', EncodingProfile::DEFAULT_CONFIGURATION);

        return array_map(
            static fn(Rendition $rendition): array => [$rendition->name, $rendition->width, $rendition->height, $rendition->videoBitrate],
            $subject->getRenditionsFor($source)
        );
    }

    public function testFullHdSourceGetsAllRenditions(): void
    {
        self::assertSame(
            [['1080p', 1920, 1080, 5000], ['720p', 1280, 720, 2800], ['480p', 854, 480, 1400], ['360p', 640, 360, 800]],
            $this->describe($this->createSource(1920, 1080))
        );
    }

    public function testRenditionsAboveTheSourceAreLeftOut(): void
    {
        self::assertSame(
            [['720p', 1280, 720, 2800], ['480p', 854, 480, 1400], ['360p', 640, 360, 800]],
            $this->describe($this->createSource(1280, 720))
        );
    }

    public function testUltraHdSourceIsLimitedToTheLargestRendition(): void
    {
        self::assertSame(['1080p', 1920, 1080, 5000], $this->describe($this->createSource(3840, 2160))[0]);
    }

    public function testPortraitSourceUsesTheShorterSide(): void
    {
        self::assertSame(
            [['1080p', 1080, 1920, 5000], ['720p', 720, 1280, 2800], ['480p', 480, 854, 1400], ['360p', 360, 640, 800]],
            $this->describe($this->createSource(1080, 1920))
        );
    }

    public function testSourceBelowSmallestRenditionIsEncodedInItsOwnSize(): void
    {
        self::assertSame([['240p', 320, 240, 800]], $this->describe($this->createSource(320, 240)));
    }

    public function testDimensionsAreEvenAndNeverAboveTheSource(): void
    {
        // 4:3 with odd dimensions: 1081 would round up to 1082
        self::assertSame([['360p', 480, 360, 800]], array_slice($this->describe($this->createSource(1441, 1081)), -1));
        self::assertSame([['241p', 320, 240, 800]], $this->describe($this->createSource(321, 241)));
    }

    public function testFrameRateIsLimitedByTheProfile(): void
    {
        $subject = EncodingProfile::fromArray('default', EncodingProfile::DEFAULT_CONFIGURATION);

        self::assertSame(25.0, $subject->getFrameRateFor($this->createSource(1920, 1080, 25.0)));
        self::assertSame(30.0, $subject->getFrameRateFor($this->createSource(1920, 1080, 60.0)));
        self::assertSame(30.0, $subject->getFrameRateFor($this->createSource(1920, 1080, 0.0)));
    }
}
