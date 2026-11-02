<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Hooks;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Hooks\DataHandlerHook;
use Mfd\AdaptiveVideo\Scan\FileScanner;
use Mfd\AdaptiveVideo\Scan\VersionScheduler;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fixture references: 1 and 8 point to the video file 1, 3 to an image.
 */
final class DataHandlerHookTest extends AbstractFunctionalTestCase
{
    private VersionRepository $versionRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ScanCandidates.csv');
        $this->versionRepository = new VersionRepository($this->get(ConnectionPool::class));

        // Only files that exist in the storage are queued
        $directory = $this->instancePath . '/fileadmin/videos';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        touch($directory . '/referenced.mp4');
    }

    private function createSubject(bool $processingEnabled = true, ?MessageBusInterface $messageBus = null): DataHandlerHook
    {
        $settings = ExtensionSettings::fromArray(['processingEnabled' => $processingEnabled ? '1' : '0']);

        return new DataHandlerHook(
            new FileScanner(
                $this->get(ConnectionPool::class),
                $this->get(OnlineMediaHelperRegistry::class),
                $this->get(StorageRepository::class),
                $settings
            ),
            new VersionScheduler($this->versionRepository, $messageBus ?? $this->get(MessageBusInterface::class), $settings),
            $this->get(ConnectionPool::class),
            $settings,
            new NullLogger()
        );
    }

    /**
     * @param array<int|string, int> $substNEWwithIDs
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function createDataHandler(array $datamap = [], array $substNEWwithIDs = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->datamap = $datamap;
        $dataHandler->substNEWwithIDs = $substNEWwithIDs;

        return $dataHandler;
    }

    /**
     * @return list<int>
     */
    private function getVersionFileUids(): array
    {
        return array_map(intval(...), $this->get(ConnectionPool::class)
            ->getConnectionForTable(VersionRepository::TABLE_NAME)
            ->select(['file_uid'], VersionRepository::TABLE_NAME)
            ->fetchFirstColumn());
    }

    public function testNewReferenceQueuesVideo(): void
    {
        $dataHandler = $this->createDataHandler(['sys_file_reference' => ['NEW123' => ['uid_local' => 1]]], ['NEW123' => 1]);

        $this->createSubject()->processDatamap_afterAllOperations($dataHandler);

        self::assertSame([1], $this->getVersionFileUids());
    }

    public function testSavingAnotherReferenceOfQueuedVideoCreatesNoSecondVersion(): void
    {
        $subject = $this->createSubject();
        $subject->processDatamap_afterAllOperations($this->createDataHandler(['sys_file_reference' => [1 => []]]));
        $subject->processDatamap_afterAllOperations($this->createDataHandler(['sys_file_reference' => [8 => []]]));

        self::assertSame([1], $this->getVersionFileUids());
    }

    public function testReferenceOfUnsupportedFileIsIgnored(): void
    {
        $this->createSubject()->processDatamap_afterAllOperations($this->createDataHandler(['sys_file_reference' => [3 => []]]));

        self::assertSame([], $this->getVersionFileUids());
    }

    public function testOtherTablesAreIgnored(): void
    {
        $this->createSubject()->processDatamap_afterAllOperations($this->createDataHandler(['tt_content' => [1 => []]]));

        self::assertSame([], $this->getVersionFileUids());
    }

    public function testCopiedReferenceQueuesVideo(): void
    {
        $dataHandler = $this->createDataHandler();
        $dataHandler->copyMappingArray_merged = ['sys_file_reference' => [1 => 8]];

        $this->createSubject()->processCmdmap_afterFinish($dataHandler);

        self::assertSame([1], $this->getVersionFileUids());
    }

    public function testNothingIsQueuedIfProcessingIsDisabled(): void
    {
        $this->createSubject(false)->processDatamap_afterAllOperations($this->createDataHandler(['sys_file_reference' => [1 => []]]));

        self::assertSame([], $this->getVersionFileUids());
    }

    public function testQueueErrorDoesNotBreakSaving(): void
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException(new \RuntimeException('Queue not reachable'));

        $this->createSubject(true, $messageBus)->processDatamap_afterAllOperations($this->createDataHandler(['sys_file_reference' => [1 => []]]));

        // The scheduler removes the version again, so the next scan queues the file
        self::assertSame([], $this->getVersionFileUids());
    }
}
