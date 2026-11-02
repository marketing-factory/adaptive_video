<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Domain\Repository;

use Mfd\AdaptiveVideo\Domain\Model\VersionStatus;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Processing\EncodingProfile;
use Mfd\AdaptiveVideo\Processing\ProfileSnapshot;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class VersionRepositoryTest extends AbstractFunctionalTestCase
{
    private const SHA1_OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA1_NEW = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const MAX_RUNTIME = 600;

    private VersionRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new VersionRepository($this->get(ConnectionPool::class));
    }

    private function createSnapshot(int $audioBitrate = 128): ProfileSnapshot
    {
        return ProfileSnapshot::create(
            EncodingProfile::fromArray('default', ['audioBitrate' => $audioBitrate] + EncodingProfile::DEFAULT_CONFIGURATION),
            []
        );
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function updateRow(int $uid, array $fields): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(VersionRepository::TABLE_NAME)
            ->update(VersionRepository::TABLE_NAME, $fields, ['uid' => $uid]);
    }

    private function createProcessingVersion(int $fileUid = 1, string $sourceFingerprint = self::SHA1_OLD, int $audioBitrate = 128): int
    {
        $uid = $this->subject->create($fileUid, $sourceFingerprint, $this->createSnapshot($audioBitrate));
        self::assertNotNull($uid);
        self::assertTrue($this->subject->claim($uid, self::MAX_RUNTIME));

        return $uid;
    }

    public function testCreateStoresPendingVersion(): void
    {
        $snapshot = $this->createSnapshot();

        $uid = $this->subject->create(42, self::SHA1_OLD, $snapshot);

        self::assertNotNull($uid);
        $version = $this->subject->findByUid($uid);
        self::assertNotNull($version);
        self::assertSame(42, $version->fileUid);
        self::assertSame(self::SHA1_OLD, $version->sourceFingerprint);
        self::assertSame($snapshot->getFingerprint(), $version->profileFingerprint);
        self::assertSame($snapshot->getFingerprint(), $version->profileSnapshot->getFingerprint());
        self::assertSame(VersionStatus::Pending, $version->status);
        self::assertGreaterThan(0, $version->createdAt);
    }

    public function testCreateReturnsNullForExistingVersionIdentity(): void
    {
        self::assertNotNull($this->subject->create(1, self::SHA1_OLD, $this->createSnapshot()));

        self::assertNull($this->subject->create(1, self::SHA1_OLD, $this->createSnapshot()));
    }

    public function testCreateReturnsNullEvenIfExistingVersionHasFailed(): void
    {
        $uid = $this->createProcessingVersion();
        $this->subject->markFailed($uid, 'broken');

        self::assertNull($this->subject->create(1, self::SHA1_OLD, $this->createSnapshot()));
    }

    public function testCreateAllowsNewVersionForChangedSourceOrProfile(): void
    {
        self::assertNotNull($this->subject->create(1, self::SHA1_OLD, $this->createSnapshot()));

        self::assertNotNull($this->subject->create(1, self::SHA1_NEW, $this->createSnapshot()));
        self::assertNotNull($this->subject->create(1, self::SHA1_OLD, $this->createSnapshot(96)));
        self::assertNotNull($this->subject->create(2, self::SHA1_OLD, $this->createSnapshot()));
    }

    public function testClaimReservesPendingVersionOnlyOnce(): void
    {
        $uid = $this->subject->create(1, self::SHA1_OLD, $this->createSnapshot());
        self::assertNotNull($uid);

        self::assertTrue($this->subject->claim($uid, self::MAX_RUNTIME));
        self::assertFalse($this->subject->claim($uid, self::MAX_RUNTIME));

        $version = $this->subject->findByUid($uid);
        self::assertSame(VersionStatus::Processing, $version?->status);
        self::assertGreaterThan(0, $version->processingStartedAt);
    }

    public function testClaimTakesOverVersionOfCrashedWorker(): void
    {
        $uid = $this->createProcessingVersion();
        $this->updateRow($uid, ['processing_started_at' => time() - self::MAX_RUNTIME - 10]);

        self::assertTrue($this->subject->claim($uid, self::MAX_RUNTIME));
        self::assertFalse($this->subject->claim($uid, self::MAX_RUNTIME));
        self::assertGreaterThan(time() - 10, $this->subject->findByUid($uid)?->processingStartedAt);
    }

    public function testClaimDoesNotTakeOverRunningVersion(): void
    {
        $uid = $this->createProcessingVersion();
        $this->updateRow($uid, ['processing_started_at' => time() - self::MAX_RUNTIME + 60]);

        self::assertFalse($this->subject->claim($uid, self::MAX_RUNTIME));
    }

    /**
     * @return iterable<string, array{VersionStatus}>
     */
    public static function finalStatusDataProvider(): iterable
    {
        yield 'ready' => [VersionStatus::Ready];
        yield 'failed' => [VersionStatus::Failed];
        yield 'skipped' => [VersionStatus::Skipped];
    }

    #[DataProvider('finalStatusDataProvider')]
    public function testClaimIgnoresFinishedVersions(VersionStatus $status): void
    {
        $uid = $this->createProcessingVersion();
        $this->updateRow($uid, ['status' => $status->value, 'processing_started_at' => 1]);

        self::assertFalse($this->subject->claim($uid, self::MAX_RUNTIME));
    }

    public function testClaimReturnsFalseForUnknownVersion(): void
    {
        self::assertFalse($this->subject->claim(4711, self::MAX_RUNTIME));
    }

    public function testMarkReadyReleasesProcessingVersion(): void
    {
        $uid = $this->createProcessingVersion();

        self::assertTrue($this->subject->markReady($uid, '1/abc'));

        $version = $this->subject->findByUid($uid);
        self::assertSame(VersionStatus::Ready, $version?->status);
        self::assertSame('1/abc', $version->outputPrefix);
        self::assertGreaterThan(0, $version->completedAt);
    }

    public function testFinishingRequiresProcessingStatus(): void
    {
        $uid = $this->subject->create(1, self::SHA1_OLD, $this->createSnapshot());
        self::assertNotNull($uid);

        self::assertFalse($this->subject->markReady($uid, '1/abc'));
        self::assertFalse($this->subject->markFailed($uid, 'error'));
        self::assertFalse($this->subject->markSkipped($uid, 'too short'));
        self::assertSame(VersionStatus::Pending, $this->subject->findByUid($uid)?->status);
    }

    public function testVersionCanOnlyBeFinishedOnce(): void
    {
        $uid = $this->createProcessingVersion();

        self::assertTrue($this->subject->markReady($uid, '1/abc'));
        self::assertFalse($this->subject->markFailed($uid, 'late failure of a second worker'));
        self::assertSame(VersionStatus::Ready, $this->subject->findByUid($uid)?->status);
    }

    public function testMarkFailedAndSkippedStoreMessage(): void
    {
        $failed = $this->createProcessingVersion(1);
        $skipped = $this->createProcessingVersion(2);

        self::assertTrue($this->subject->markFailed($failed, str_repeat('x', 5000)));
        self::assertTrue($this->subject->markSkipped($skipped, 'Duration below minimum'));

        $failedVersion = $this->subject->findByUid($failed);
        self::assertSame(VersionStatus::Failed, $failedVersion?->status);
        self::assertSame(2000, mb_strlen($failedVersion->errorMessage));
        $skippedVersion = $this->subject->findByUid($skipped);
        self::assertSame(VersionStatus::Skipped, $skippedVersion?->status);
        self::assertSame('Duration below minimum', $skippedVersion->errorMessage);
    }

    public function testFindActiveReturnsNullWithoutReadyVersion(): void
    {
        $this->createProcessingVersion();

        self::assertNull($this->subject->findActive(1, self::SHA1_OLD));
    }

    public function testFindActiveKeepsPreviousVersionWhileNewProfileIsProcessing(): void
    {
        $previous = $this->createProcessingVersion(audioBitrate: 128);
        $this->subject->markReady($previous, '1/previous');
        $this->createProcessingVersion(audioBitrate: 96);

        self::assertSame($previous, $this->subject->findActive(1, self::SHA1_OLD)?->uid);
    }

    public function testFindActiveReturnsNewestReadyVersion(): void
    {
        $previous = $this->createProcessingVersion(audioBitrate: 128);
        $this->subject->markReady($previous, '1/previous');
        $newest = $this->createProcessingVersion(audioBitrate: 96);
        $this->subject->markReady($newest, '1/newest');

        self::assertSame($newest, $this->subject->findActive(1, self::SHA1_OLD)?->uid);
    }

    public function testFindActiveIgnoresVersionsOfChangedSource(): void
    {
        $uid = $this->createProcessingVersion(sourceFingerprint: self::SHA1_OLD);
        $this->subject->markReady($uid, '1/old');

        self::assertNull($this->subject->findActive(1, self::SHA1_NEW));
        self::assertNull($this->subject->findActive(2, self::SHA1_OLD));
    }

    public function testFindByFileUidReturnsVersionsOfFileNewestFirst(): void
    {
        $previous = $this->subject->create(1, self::SHA1_OLD, $this->createSnapshot());
        $newest = $this->subject->create(1, self::SHA1_NEW, $this->createSnapshot());
        $this->subject->create(2, self::SHA1_OLD, $this->createSnapshot());

        self::assertSame([$newest, $previous], array_column($this->subject->findByFileUid(1), 'uid'));
        self::assertSame([], $this->subject->findByFileUid(3));
    }

    public function testFindAndCountByStatus(): void
    {
        $this->subject->create(1, self::SHA1_OLD, $this->createSnapshot());
        $failed = $this->createProcessingVersion(fileUid: 2);
        $this->subject->markFailed($failed, 'error');
        $processing = $this->createProcessingVersion(fileUid: 3);

        self::assertSame([$processing], array_column($this->subject->findByStatus(VersionStatus::Processing), 'uid'));
        self::assertSame([$failed], array_column($this->subject->findByStatus(VersionStatus::Failed), 'uid'));
        self::assertSame(
            ['pending' => 1, 'processing' => 1, 'ready' => 0, 'failed' => 1, 'skipped' => 0],
            $this->subject->countByStatus()
        );
    }
}
