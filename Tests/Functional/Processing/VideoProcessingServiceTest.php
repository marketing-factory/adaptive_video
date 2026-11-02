<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Processing;

use Mfd\AdaptiveVideo\Cache\CacheInvalidator;
use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Model\Version;
use Mfd\AdaptiveVideo\Domain\Model\VersionStatus;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Processing\ArtifactValidator;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Mfd\AdaptiveVideo\Processing\FfmpegEncoder;
use Mfd\AdaptiveVideo\Processing\Rendition;
use Mfd\AdaptiveVideo\Processing\SourceAnalyzer;
use Mfd\AdaptiveVideo\Processing\SourceInfo;
use Mfd\AdaptiveVideo\Processing\VideoProcessingService;
use Mfd\AdaptiveVideo\Storage\LocalFilesystemStorage;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class VideoProcessingServiceTest extends AbstractFunctionalTestCase
{
    private const FILE_UID = 1;
    private const FILE_CONTENT = 'not a real video, ffprobe is stubbed';

    private VersionRepository $versionRepository;
    private string $sha1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Storage.csv');
        $this->versionRepository = new VersionRepository($this->get(ConnectionPool::class));
        // Version uids start again with every test, the instance directory is kept
        (new Filesystem())->remove($this->instancePath . '/_videos');

        $directory = $this->instancePath . '/fileadmin/videos';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/source.mp4', self::FILE_CONTENT);
        $this->sha1 = sha1(self::FILE_CONTENT);
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => self::FILE_UID,
            'storage' => 1,
            'identifier' => '/videos/source.mp4',
            'identifier_hash' => sha1('/videos/source.mp4'),
            'folder_hash' => sha1('/videos'),
            'name' => 'source.mp4',
            'extension' => 'mp4',
            'mime_type' => 'video/mp4',
            'sha1' => $this->sha1,
            'size' => strlen(self::FILE_CONTENT),
        ]);
    }

    /**
     * @param array<string, string> $configuration
     */
    private function createSubject(
        SourceAnalyzer $sourceAnalyzer,
        array $configuration = [],
        ?FfmpegEncoder $encoder = null,
        ?ArtifactValidator $validator = null,
        ?CacheInvalidator $cacheInvalidator = null,
    ): VideoProcessingService {
        $settings = ExtensionSettings::fromArray($configuration + ['maxRuntime' => '600', 'redeliverTimeout' => '900']);

        return new VideoProcessingService(
            $this->versionRepository,
            $sourceAnalyzer,
            $encoder ?? $this->createEncoder(),
            $validator ?? $this->createStub(ArtifactValidator::class),
            new LocalFilesystemStorage($settings),
            $this->get(FileIndexRepository::class),
            $this->get(ResourceFactory::class),
            $settings,
            $cacheInvalidator ?? $this->createStub(CacheInvalidator::class),
            new NullLogger()
        );
    }

    /**
     * Writes a master playlist instead of running ffmpeg.
     *
     * @param list<list<string>> $renditionNames receives the rendition names of each call
     */
    private function createEncoder(array &$renditionNames = []): FfmpegEncoder&Stub
    {
        $encoder = $this->createStub(FfmpegEncoder::class);
        $encoder->method('encode')->willReturnCallback(
            static function (string $sourcePath, SourceInfo $source, array $renditions, string $outputDirectory) use (&$renditionNames): void {
                $renditionNames[] = array_map(static fn(Rendition $rendition): string => $rendition->name, $renditions);
                file_put_contents($outputDirectory . '/master.m3u8', '#EXTM3U');
            }
        );

        return $encoder;
    }

    private function getWorkingDirectory(int $versionUid): string
    {
        return $this->instancePath . '/typo3temp/var/transient/adaptive_video/version_' . $versionUid;
    }

    private function createAnalyzer(float $duration = 60.0): SourceAnalyzer&Stub
    {
        $sourceAnalyzer = $this->createStub(SourceAnalyzer::class);
        $sourceAnalyzer->method('analyze')->willReturn(SourceInfo::fromFfprobeData([
            'streams' => [['index' => 0, 'codec_type' => 'video', 'width' => 1920, 'height' => 1080]],
            'format' => ['duration' => (string)$duration],
        ]));

        return $sourceAnalyzer;
    }

    /**
     * @param array<string, string> $configuration settings the version was created with
     */
    private function createVersion(?string $sourceFingerprint = null, int $fileUid = self::FILE_UID, array $configuration = []): int
    {
        $uid = $this->versionRepository->create(
            $fileUid,
            $sourceFingerprint ?? $this->sha1,
            ExtensionSettings::fromArray($configuration)->createProfileSnapshot()
        );
        self::assertNotNull($uid);

        return $uid;
    }

    private function getVersion(int $uid): Version
    {
        $version = $this->versionRepository->findByUid($uid);
        self::assertNotNull($version);

        return $version;
    }

    public function testUnknownVersionIsIgnored(): void
    {
        $sourceAnalyzer = $this->createMock(SourceAnalyzer::class);
        $sourceAnalyzer->expects(self::never())->method('analyze');

        $this->createSubject($sourceAnalyzer)->process(4711);
    }

    public function testVersionClaimedByAnotherWorkerIsNotProcessed(): void
    {
        $uid = $this->createVersion();
        self::assertTrue($this->versionRepository->claim($uid, 600));
        $sourceAnalyzer = $this->createMock(SourceAnalyzer::class);
        $sourceAnalyzer->expects(self::never())->method('analyze');

        $this->createSubject($sourceAnalyzer)->process($uid);

        self::assertSame(VersionStatus::Processing, $this->getVersion($uid)->status);
    }

    public function testAnalyzerReceivesLocalPathOfSourceFile(): void
    {
        $uid = $this->createVersion();
        $sourceAnalyzer = $this->createMock(SourceAnalyzer::class);
        $sourceAnalyzer->expects(self::once())->method('analyze')
            ->with($this->instancePath . '/fileadmin/videos/source.mp4')
            ->willThrowException(new ProcessingException('stop here', 1));

        $this->createSubject($sourceAnalyzer)->process($uid);
    }

    public function testMissingFileRecordFailsVersion(): void
    {
        $uid = $this->createVersion(fileUid: 99);

        $this->createSubject($this->createAnalyzer())->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Failed, $version->status);
        self::assertSame('File 99 does not exist.', $version->errorMessage);
    }

    public function testChangedSourceSkipsVersion(): void
    {
        $uid = $this->createVersion(str_repeat('0', 40));

        $this->createSubject($this->createAnalyzer())->process($uid);

        self::assertSame(VersionStatus::Skipped, $this->getVersion($uid)->status);
    }

    public function testFileAboveSizeLimitIsSkipped(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')
            ->update('sys_file', ['size' => 2 * 1024 * 1024], ['uid' => self::FILE_UID]);
        $uid = $this->createVersion();

        $this->createSubject($this->createAnalyzer(), ['maxSourceFileSize' => '1'])->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Skipped, $version->status);
        self::assertSame('Source file is larger than 1 MB.', $version->errorMessage);
    }

    public function testVideoBelowMinimumDurationIsSkipped(): void
    {
        $uid = $this->createVersion();

        $this->createSubject($this->createAnalyzer(9.5), ['minimumDuration' => '10'])->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Skipped, $version->status);
        self::assertSame('Duration is below the minimum of 10 seconds.', $version->errorMessage);
    }

    public function testVideoAboveMaximumDurationIsSkipped(): void
    {
        $uid = $this->createVersion();

        $this->createSubject($this->createAnalyzer(3601), ['maxSourceDuration' => '3600'])->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Skipped, $version->status);
        self::assertSame('Duration exceeds the maximum of 3600 seconds.', $version->errorMessage);
    }

    public function testAnalyzerErrorFailsVersion(): void
    {
        $uid = $this->createVersion();
        $sourceAnalyzer = $this->createStub(SourceAnalyzer::class);
        $sourceAnalyzer->method('analyze')->willThrowException(new ProcessingException('ffprobe failed (exit code 1): Invalid data', 1));

        $this->createSubject($sourceAnalyzer)->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Failed, $version->status);
        self::assertSame('ffprobe failed (exit code 1): Invalid data', $version->errorMessage);
    }

    public function testChangedProfileSkipsVersion(): void
    {
        $uid = $this->createVersion(configuration: ['ffmpegOptions' => '-preset slow']);

        $this->createSubject($this->createAnalyzer())->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Skipped, $version->status);
        self::assertSame('Encoding profile changed after the version was created.', $version->errorMessage);
    }

    public function testValidSourceIsPublishedAndBecomesReady(): void
    {
        $uid = $this->createVersion();
        $renditionNames = [];
        $cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $cacheInvalidator->expects(self::once())->method('flush')->with(self::FILE_UID);

        $this->createSubject($this->createAnalyzer(), encoder: $this->createEncoder($renditionNames), cacheInvalidator: $cacheInvalidator)->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Ready, $version->status);
        self::assertSame('1/' . $uid, $version->outputPrefix);
        self::assertGreaterThan(0, $version->completedAt);
        self::assertSame([['1080p', '720p', '480p', '360p']], $renditionNames);
        self::assertFileExists($this->instancePath . '/_videos/1/' . $uid . '/master.m3u8');
        self::assertDirectoryDoesNotExist($this->getWorkingDirectory($uid));
        self::assertNotNull($this->versionRepository->findActive(self::FILE_UID, $this->sha1));
    }

    public function testEncoderErrorFailsVersionAndRemovesWorkingDirectory(): void
    {
        $uid = $this->createVersion();
        $encoder = $this->createStub(FfmpegEncoder::class);
        $encoder->method('encode')->willReturnCallback(static function (string $sourcePath, SourceInfo $source, array $renditions, string $outputDirectory): void {
            file_put_contents($outputDirectory . '/master.m3u8', '#EXTM3U');
            throw new ProcessingException('ffmpeg failed (exit code 1): Conversion failed!', 1);
        });

        $this->createSubject($this->createAnalyzer(), encoder: $encoder)->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Failed, $version->status);
        self::assertSame('ffmpeg failed (exit code 1): Conversion failed!', $version->errorMessage);
        self::assertDirectoryDoesNotExist($this->getWorkingDirectory($uid));
        self::assertNull($this->versionRepository->findActive(self::FILE_UID, $this->sha1));
    }

    public function testInvalidOutputIsNotPublished(): void
    {
        $uid = $this->createVersion();
        $validator = $this->createStub(ArtifactValidator::class);
        $validator->method('validate')->willThrowException(new ProcessingException('Segment 720p/segment_00001.m4s is missing or empty.', 1));

        $this->createSubject($this->createAnalyzer(), validator: $validator)->process($uid);

        $version = $this->getVersion($uid);
        self::assertSame(VersionStatus::Failed, $version->status);
        self::assertSame('Segment 720p/segment_00001.m4s is missing or empty.', $version->errorMessage);
        self::assertDirectoryDoesNotExist($this->instancePath . '/_videos/1/' . $uid);
        self::assertDirectoryDoesNotExist($this->getWorkingDirectory($uid));
    }
}
