<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Mfd\AdaptiveVideo\Domain\Model\Version;
use Mfd\AdaptiveVideo\Domain\Model\VersionStatus;
use Mfd\AdaptiveVideo\Processing\ProfileSnapshot;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Concurrency safety relies on the unique index and on conditional single-row
 * updates whose affected row count tells whether the transition happened; no
 * locks or transactions are needed (see technical concept §4).
 *
 * Timestamps use time() instead of the request time, because a messenger
 * worker runs for a long time within a single process.
 */
final readonly class VersionRepository
{
    public const TABLE_NAME = 'tx_adaptivevideo_version';

    private const MAX_ERROR_MESSAGE_LENGTH = 2000;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Creates a pending version. Returns null if a version for this file,
     * source content and profile already exists, whatever its status.
     */
    public function create(int $fileUid, string $sourceFingerprint, ProfileSnapshot $profileSnapshot): ?int
    {
        $connection = $this->getConnection();
        try {
            $connection->insert(self::TABLE_NAME, [
                'file_uid' => $fileUid,
                'source_fingerprint' => $sourceFingerprint,
                'profile_fingerprint' => $profileSnapshot->getFingerprint(),
                'profile_snapshot' => $profileSnapshot->toJson(),
                'status' => VersionStatus::Pending->value,
                'created_at' => time(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return (int)$connection->lastInsertId();
    }

    /**
     * Reserves a version for processing. Succeeds for pending versions and
     * takes over versions whose worker has not finished within $maxRuntime
     * seconds (crashed or killed worker). Only one caller can succeed.
     */
    public function claim(int $uid, int $maxRuntime): bool
    {
        $now = time();
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $affectedRows = $queryBuilder
            ->update(self::TABLE_NAME)
            ->set('status', VersionStatus::Processing->value)
            ->set('processing_started_at', $now, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(VersionStatus::Pending->value)),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(VersionStatus::Processing->value)),
                        $queryBuilder->expr()->lt(
                            'processing_started_at',
                            $queryBuilder->createNamedParameter($now - $maxRuntime, Connection::PARAM_INT)
                        )
                    )
                )
            )
            ->executeStatement();

        return $affectedRows === 1;
    }

    /**
     * Releases a processed version. From now on it is the active version of
     * its file, as long as the source fingerprint matches.
     */
    public function markReady(int $uid, string $outputPrefix): bool
    {
        return $this->finish($uid, VersionStatus::Ready, ['output_prefix' => $outputPrefix]);
    }

    public function markFailed(int $uid, string $errorMessage): bool
    {
        return $this->finish($uid, VersionStatus::Failed, ['error_message' => $this->truncate($errorMessage)]);
    }

    public function markSkipped(int $uid, string $reason): bool
    {
        return $this->finish($uid, VersionStatus::Skipped, ['error_message' => $this->truncate($reason)]);
    }

    public function delete(int $uid): void
    {
        $this->getConnection()->delete(self::TABLE_NAME, ['uid' => $uid]);
    }

    public function findByUid(int $uid): ?Version
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : Version::fromRow($row);
    }

    /**
     * Newest ready version matching the current content of the file. Returns
     * null if the file changed since, so the original is delivered (ADR-010).
     */
    public function findActive(int $fileUid, string $sourceFingerprint): ?Version
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('file_uid', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(VersionStatus::Ready->value)),
                $queryBuilder->expr()->eq('source_fingerprint', $queryBuilder->createNamedParameter($sourceFingerprint))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : Version::fromRow($row);
    }

    /**
     * All versions of a file, newest first.
     *
     * @return list<Version>
     */
    public function findByFileUid(int $fileUid): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->eq('file_uid', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)))
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(Version::fromRow(...), $rows);
    }

    /**
     * Versions with the given status, oldest first.
     *
     * @return list<Version>
     */
    public function findByStatus(VersionStatus $status): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status->value)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(Version::fromRow(...), $rows);
    }

    /**
     * @return array<value-of<VersionStatus>, int> number of versions per status, including empty ones
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(array_column(VersionStatus::cases(), 'value'), 0);
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('status')
            ->addSelectLiteral('COUNT(*) AS count')
            ->from(self::TABLE_NAME)
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Single-row update from "processing" to a final status, therefore atomic.
     *
     * @param array<string, string> $fields
     */
    private function finish(int $uid, VersionStatus $status, array $fields): bool
    {
        $affectedRows = $this->getConnection()->update(
            self::TABLE_NAME,
            ['status' => $status->value, 'completed_at' => time()] + $fields,
            ['uid' => $uid, 'status' => VersionStatus::Processing->value]
        );

        return $affectedRows === 1;
    }

    private function truncate(string $message): string
    {
        return mb_substr($message, 0, self::MAX_ERROR_MESSAGE_LENGTH);
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
    }
}
