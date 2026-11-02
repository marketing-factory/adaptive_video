<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Hooks;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Scan\FileScanner;
use Mfd\AdaptiveVideo\Scan\VersionScheduler;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Queues videos as soon as a file reference is saved, copied or localized in
 * the backend, instead of waiting for the next scan. The same rules as in the
 * scan apply; the scan stays the main way and catches everything that does
 * not pass the DataHandler.
 */
final readonly class DataHandlerHook
{
    private const REFERENCE_TABLE = 'sys_file_reference';

    public function __construct(
        private FileScanner $fileScanner,
        private VersionScheduler $versionScheduler,
        private ConnectionPool $connectionPool,
        private ExtensionSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $referenceUids = [];
        foreach (array_keys($dataHandler->datamap[self::REFERENCE_TABLE] ?? []) as $id) {
            // New records are submitted with a "NEW…" placeholder
            $referenceUids[] = $dataHandler->substNEWwithIDs[$id] ?? $id;
        }

        $this->scheduleReferencedFiles($referenceUids);
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        // References created by copying or localizing their parent record
        $this->scheduleReferencedFiles(array_values($dataHandler->copyMappingArray_merged[self::REFERENCE_TABLE] ?? []));
    }

    /**
     * @param array<int|string> $referenceUids
     */
    private function scheduleReferencedFiles(array $referenceUids): void
    {
        $referenceUids = array_values(array_unique(array_map(
            intval(...),
            array_filter($referenceUids, MathUtility::canBeInterpretedAsInteger(...))
        )));
        if ($referenceUids === [] || !$this->settings->processingEnabled) {
            return;
        }

        try {
            foreach ($this->fileScanner->findFilesWithoutVersion($this->findFileUids($referenceUids)) as $file) {
                if ($this->fileScanner->fileExists($file)) {
                    $this->versionScheduler->schedule($file['uid'], $file['sha1']);
                }
            }
        } catch (\Throwable $e) {
            // Saving must not fail because of the queue; the next scan catches up
            $this->logger->warning('Queuing videos after saving references failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param list<int> $referenceUids
     * @return list<int>
     */
    private function findFileUids(array $referenceUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $fileUids = $queryBuilder
            ->select('uid_local')
            ->from(self::REFERENCE_TABLE)
            ->where($queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($referenceUids, Connection::PARAM_INT_ARRAY)
            ))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_unique(array_map(intval(...), $fileUids)));
    }
}
