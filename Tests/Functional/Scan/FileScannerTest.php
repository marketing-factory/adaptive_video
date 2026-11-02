<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Scan;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Processing\ProfileSnapshot;
use Mfd\AdaptiveVideo\Scan\FileScanner;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class FileScannerTest extends AbstractFunctionalTestCase
{
    private const SHA1_FILE_1 = '1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ScanCandidates.csv');
    }

    /**
     * @param array<string, string> $configuration
     */
    private function createSettings(array $configuration = []): ExtensionSettings
    {
        return ExtensionSettings::fromArray($configuration);
    }

    private function createSubject(ExtensionSettings $settings): FileScanner
    {
        return new FileScanner(
            $this->get(ConnectionPool::class),
            $this->get(OnlineMediaHelperRegistry::class),
            $this->get(StorageRepository::class),
            $settings
        );
    }

    /**
     * @return list<int>
     */
    private function findFileUids(ExtensionSettings $settings): array
    {
        $subject = $this->createSubject($settings);

        return array_column(iterator_to_array($subject->findFilesWithoutVersion(), false), 'uid');
    }

    private function createVersion(int $fileUid, string $sourceFingerprint, ProfileSnapshot $snapshot): void
    {
        (new VersionRepository($this->get(ConnectionPool::class)))->create($fileUid, $sourceFingerprint, $snapshot);
    }

    public function testFindsReferencedSupportedFilesOnlyOnce(): void
    {
        // Not found: 2 (no reference), 3 (reference deleted), 4 (jpg), 5 (online media),
        // 6 (missing), 7 (not indexed). Hidden references count (8).
        self::assertSame([1, 8], $this->findFileUids($this->createSettings()));
    }

    public function testSearchCanBeLimitedToFiles(): void
    {
        $subject = $this->createSubject($this->createSettings());

        self::assertSame([8], array_column(iterator_to_array($subject->findFilesWithoutVersion([2, 4, 8]), false), 'uid'));
        self::assertSame([], iterator_to_array($subject->findFilesWithoutVersion([]), false));
    }

    public function testReturnsFileData(): void
    {
        $subject = $this->createSubject($this->createSettings(['supportedFileExtensions' => 'mp4']));

        self::assertSame(
            [['uid' => 1, 'storage' => 1, 'identifier' => '/videos/referenced.mp4', 'sha1' => self::SHA1_FILE_1]],
            iterator_to_array($subject->findFilesWithoutVersion(), false)
        );
    }

    public function testOnlineMediaIsExcludedEvenIfConfigured(): void
    {
        self::assertSame([1], $this->findFileUids($this->createSettings(['supportedFileExtensions' => 'mp4,youtube'])));
    }

    public function testNothingIsFoundWithoutSupportedExtensions(): void
    {
        self::assertSame([], $this->findFileUids($this->createSettings(['supportedFileExtensions' => 'youtube'])));
    }

    public function testFileWithVersionForCurrentContentAndProfileIsSkipped(): void
    {
        $settings = $this->createSettings();
        $this->createVersion(1, self::SHA1_FILE_1, $settings->createProfileSnapshot());

        self::assertSame([8], $this->findFileUids($settings));
    }

    public function testFileWithVersionOfOldContentIsFound(): void
    {
        $settings = $this->createSettings();
        $this->createVersion(1, 'ffffffffffffffffffffffffffffffffffffffff', $settings->createProfileSnapshot());

        self::assertSame([1, 8], $this->findFileUids($settings));
    }

    public function testFileWithVersionOfOtherProfileIsFound(): void
    {
        $this->createVersion(1, self::SHA1_FILE_1, $this->createSettings(['ffmpegOptions' => '-preset slow'])->createProfileSnapshot());

        self::assertSame([1, 8], $this->findFileUids($this->createSettings()));
    }

    public function testFileExistsChecksTheStorage(): void
    {
        $directory = $this->instancePath . '/fileadmin/videos';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/referenced.mp4', 'video');
        $subject = $this->createSubject($this->createSettings());

        self::assertTrue($subject->fileExists(['storage' => 1, 'identifier' => '/videos/referenced.mp4']));
        self::assertFalse($subject->fileExists(['storage' => 1, 'identifier' => '/videos/hidden-reference.mov']));
        self::assertFalse($subject->fileExists(['storage' => 99, 'identifier' => '/videos/referenced.mp4']));
    }
}
