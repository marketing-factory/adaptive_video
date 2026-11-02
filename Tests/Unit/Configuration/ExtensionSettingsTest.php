<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Configuration;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExtensionSettingsTest extends UnitTestCase
{
    public function testDefaultsMatchTheConcept(): void
    {
        $subject = ExtensionSettings::fromArray([]);

        self::assertFalse($subject->processingEnabled);
        self::assertTrue($subject->deliveryEnabled);
        self::assertSame('default', $subject->profile->identifier);
        self::assertSame(['mp4', 'mov', 'm4v', 'webm'], $subject->supportedFileExtensions);
        self::assertSame(10, $subject->minimumDuration);
        self::assertSame('ffmpeg', $subject->ffmpegPath);
        self::assertSame('ffprobe', $subject->ffprobePath);
        self::assertSame([], $subject->commandPrefix);
        self::assertSame([], $subject->ffmpegOptions);
        self::assertSame(10800, $subject->maxRuntime);
        self::assertSame(14400, $subject->redeliverTimeout);
        self::assertSame(0, $subject->maxSourceFileSize);
        self::assertSame(0, $subject->maxSourceDuration);
        self::assertSame('_videos', $subject->artifactDirectory);
        self::assertSame('/_videos/', $subject->publicUrlPrefix);
        self::assertSame(Environment::getVarPath() . '/transient/adaptive_video', $subject->temporaryDirectory);
    }

    public function testValuesFromExtensionConfigurationAreParsed(): void
    {
        $subject = ExtensionSettings::fromArray([
            'processingEnabled' => '1',
            'deliveryEnabled' => '0',
            'supportedFileExtensions' => ' MP4, mov ,',
            'minimumDuration' => '5',
            'ffmpegPath' => '/usr/bin/ffmpeg',
            'commandPrefix' => '',
            'ffmpegOptions' => ' -threads  2   -preset slow ',
            'maxRuntime' => '3600',
            'artifactDirectory' => '/media/streams/',
            'publicUrlPrefix' => '/media/streams',
            'temporaryDirectory' => 'var/encoding/',
        ]);

        self::assertTrue($subject->processingEnabled);
        self::assertFalse($subject->deliveryEnabled);
        self::assertSame(['mp4', 'mov'], $subject->supportedFileExtensions);
        self::assertSame(5, $subject->minimumDuration);
        self::assertSame('/usr/bin/ffmpeg', $subject->ffmpegPath);
        self::assertSame([], $subject->commandPrefix);
        self::assertSame(['-threads', '2', '-preset', 'slow'], $subject->ffmpegOptions);
        self::assertSame(3600, $subject->maxRuntime);
        self::assertSame('media/streams', $subject->artifactDirectory);
        self::assertSame(Environment::getPublicPath() . '/media/streams', $subject->getAbsoluteArtifactDirectory());
        self::assertSame('/media/streams/', $subject->publicUrlPrefix);
        self::assertSame(Environment::getProjectPath() . '/var/encoding', $subject->temporaryDirectory);
    }

    public function testFileExtensionCheckIsCaseInsensitive(): void
    {
        $subject = ExtensionSettings::fromArray([]);

        self::assertTrue($subject->isSupportedFileExtension('MP4'));
        self::assertFalse($subject->isSupportedFileExtension('youtube'));
    }

    public function testConfiguredProfileIsUsed(): void
    {
        $subject = ExtensionSettings::fromArray(
            ['defaultProfile' => 'small'],
            [
                'small' => [
                    'renditions' => [['height' => 360, 'videoBitrate' => 600]],
                    'audioBitrate' => 96,
                    'audioSampleRate' => 44100,
                    'maxFrameRate' => 25,
                    'segmentDuration' => 6,
                ],
            ]
        );

        self::assertSame('small', $subject->profile->identifier);
        self::assertSame([['height' => 360, 'videoBitrate' => 600]], $subject->profile->renditions);
    }

    public function testMaxRuntimeMustBeLowerThanRedeliverTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1793000023);

        ExtensionSettings::fromArray(['maxRuntime' => '3600', 'redeliverTimeout' => '3600']);
    }

    public function testProfileSnapshotUsesProfileAndFfmpegOptions(): void
    {
        $subject = ExtensionSettings::fromArray(['ffmpegOptions' => '-threads 2 -preset slow']);

        $snapshot = $subject->createProfileSnapshot();

        self::assertSame('default', $snapshot->data['profile']);
        self::assertSame(['-preset', 'slow'], $snapshot->data['ffmpegOptions']);
    }

    public function testUnknownProfileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1793000020);

        ExtensionSettings::fromArray(['defaultProfile' => 'unknown']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidArtifactDirectoryDataProvider(): iterable
    {
        yield 'parent directory' => ['../outside'];
        yield 'parent directory in between' => ['_videos/../../outside'];
        yield 'current directory' => ['./_videos'];
        yield 'empty segment' => ['_videos//streams'];
    }

    #[DataProvider('invalidArtifactDirectoryDataProvider')]
    public function testArtifactDirectoryOutsideOfWebRootIsRejected(string $directory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1793000021);

        ExtensionSettings::fromArray(['artifactDirectory' => $directory]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPublicUrlPrefixDataProvider(): iterable
    {
        yield 'relative path' => ['_videos/'];
        yield 'protocol-relative URL' => ['//cdn.example.com/_videos/'];
        yield 'absolute URL' => ['https://cdn.example.com/_videos/'];
    }

    #[DataProvider('invalidPublicUrlPrefixDataProvider')]
    public function testPublicUrlPrefixWithHostIsRejected(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1793000022);

        ExtensionSettings::fromArray(['publicUrlPrefix' => $prefix]);
    }

    public function testCreateFallsBackToDefaultsIfExtensionIsNotConfigured(): void
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1)
        );

        self::assertEquals(ExtensionSettings::fromArray([]), ExtensionSettings::create($extensionConfiguration));
    }
}
