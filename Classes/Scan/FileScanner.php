<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Scan;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Finds files that need a new version: supported video files with at least
 * one non-deleted reference, independent of the referencing table and field,
 * for which no version exists for the current content and profile.
 */
final readonly class FileScanner
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private OnlineMediaHelperRegistry $onlineMediaHelperRegistry,
        private StorageRepository $storageRepository,
        private ExtensionSettings $settings,
    ) {
    }

    /**
     * @param list<int>|null $fileUids limits the search to these files, null searches all
     * @return iterable<array{uid: int, storage: int, identifier: string, sha1: string}>
     */
    public function findFilesWithoutVersion(?array $fileUids = null): iterable
    {
        if ($fileUids === []) {
            return;
        }

        // Online media (.youtube, .vimeo, …) are never processed, whatever is configured
        $extensions = array_values(array_diff(
            $this->settings->supportedFileExtensions,
            $this->onlineMediaHelperRegistry->getSupportedFileExtensions()
        ));
        if ($extensions === []) {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $referenceSubQuery = sprintf(
            'SELECT 1 FROM %s r WHERE r.%s = %s AND r.%s = 0',
            $queryBuilder->quoteIdentifier('sys_file_reference'),
            $queryBuilder->quoteIdentifier('uid_local'),
            $queryBuilder->quoteIdentifier('f.uid'),
            $queryBuilder->quoteIdentifier('deleted')
        );
        $versionSubQuery = sprintf(
            'SELECT 1 FROM %s v WHERE v.%s = %s AND v.%s = %s AND v.%s = %s',
            $queryBuilder->quoteIdentifier(VersionRepository::TABLE_NAME),
            $queryBuilder->quoteIdentifier('file_uid'),
            $queryBuilder->quoteIdentifier('f.uid'),
            $queryBuilder->quoteIdentifier('source_fingerprint'),
            $queryBuilder->quoteIdentifier('f.sha1'),
            $queryBuilder->quoteIdentifier('profile_fingerprint'),
            $queryBuilder->createNamedParameter($this->settings->createProfileSnapshot()->getFingerprint())
        );

        $queryBuilder
            ->select('f.uid', 'f.storage', 'f.identifier', 'f.sha1')
            ->from('sys_file', 'f')
            ->where(
                $queryBuilder->expr()->in(
                    'f.extension',
                    $queryBuilder->createNamedParameter($extensions, Connection::PARAM_STR_ARRAY)
                ),
                $queryBuilder->expr()->eq('f.missing', 0),
                $queryBuilder->expr()->neq('f.sha1', $queryBuilder->createNamedParameter('')),
                'EXISTS (' . $referenceSubQuery . ')',
                'NOT EXISTS (' . $versionSubQuery . ')'
            )
            ->orderBy('f.uid');
        if ($fileUids !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->in(
                'f.uid',
                $queryBuilder->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)
            ));
        }

        $result = $queryBuilder->executeQuery();

        while ($row = $result->fetchAssociative()) {
            yield [
                'uid' => (int)$row['uid'],
                'storage' => (int)$row['storage'],
                'identifier' => (string)$row['identifier'],
                'sha1' => (string)$row['sha1'],
            ];
        }
    }

    /**
     * The index can list files that are not in the storage (yet), e.g. on a
     * system with a database copy but without the files. A version for such a
     * file would only fail; the next scan picks the file up once it exists.
     *
     * @param array{storage: int, identifier: string} $file
     */
    public function fileExists(array $file): bool
    {
        $storage = $this->storageRepository->findByUid($file['storage']);

        return $storage !== null && $storage->hasFile($file['identifier']);
    }
}
