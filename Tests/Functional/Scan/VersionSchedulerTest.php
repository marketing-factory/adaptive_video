<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Scan;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Model\VersionStatus;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Messenger\Message\ProcessVideoMessage;
use Mfd\AdaptiveVideo\Scan\VersionScheduler;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class VersionSchedulerTest extends AbstractFunctionalTestCase
{
    private const SHA1 = '1111111111111111111111111111111111111111';

    private VersionRepository $versionRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versionRepository = new VersionRepository($this->get(ConnectionPool::class));
    }

    private function createSubject(bool $processingEnabled = true, ?MessageBusInterface $messageBus = null): VersionScheduler
    {
        return new VersionScheduler(
            $this->versionRepository,
            $messageBus ?? $this->get(MessageBusInterface::class),
            ExtensionSettings::fromArray(['processingEnabled' => $processingEnabled ? '1' : '0'])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getQueuedMessages(): array
    {
        return $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_messenger_messages')
            ->select(['queue_name', 'body'], 'sys_messenger_messages')
            ->fetchAllAssociative();
    }

    public function testScheduleCreatesVersionAndQueuesMessageOnAdaptiveVideoTransport(): void
    {
        $versionUid = $this->createSubject()->schedule(1, self::SHA1);

        self::assertNotNull($versionUid);
        $messages = $this->getQueuedMessages();
        self::assertCount(1, $messages);
        self::assertSame('adaptive_video', $messages[0]['queue_name']);
        self::assertStringContainsString(addslashes(ProcessVideoMessage::class), (string)$messages[0]['body']);
        // Not handled synchronously via the "*" fallback route
        self::assertSame(VersionStatus::Pending, $this->versionRepository->findByUid($versionUid)?->status);
    }

    public function testScheduleIsIdempotent(): void
    {
        $subject = $this->createSubject();
        self::assertNotNull($subject->schedule(1, self::SHA1));

        self::assertNull($subject->schedule(1, self::SHA1));
        self::assertCount(1, $this->getQueuedMessages());
    }

    public function testNothingIsScheduledIfProcessingIsDisabled(): void
    {
        self::assertNull($this->createSubject(false)->schedule(1, self::SHA1));

        self::assertSame([], $this->getQueuedMessages());
        self::assertNull(
            $this->versionRepository->findActive(1, self::SHA1)
        );
        self::assertSame(
            0,
            $this->get(ConnectionPool::class)->getConnectionForTable(VersionRepository::TABLE_NAME)->count('*', VersionRepository::TABLE_NAME, [])
        );
    }

    public function testVersionIsRemovedIfMessageCannotBeQueued(): void
    {
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException(new \RuntimeException('Transport unavailable', 1));

        try {
            $this->createSubject(true, $messageBus)->schedule(1, self::SHA1);
            self::fail('Exception expected');
        } catch (\RuntimeException $e) {
            self::assertSame('Transport unavailable', $e->getMessage());
        }

        // A later scan can schedule the file again
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static fn(object $message): Envelope => new Envelope($message));
        self::assertNotNull($this->createSubject(true, $messageBus)->schedule(1, self::SHA1));
    }
}
