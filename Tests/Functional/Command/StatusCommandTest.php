<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Command;

use Mfd\AdaptiveVideo\Command\StatusCommand;
use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Storage\LocalFilesystemStorage;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;

final class StatusCommandTest extends AbstractFunctionalTestCase
{
    private const SHA1 = '1111111111111111111111111111111111111111';

    private VersionRepository $versionRepository;
    private ExtensionSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        // File 1 is /videos/referenced.mp4 with content SHA1
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ScanCandidates.csv');
        $this->versionRepository = new VersionRepository($this->get(ConnectionPool::class));
        $this->settings = ExtensionSettings::fromArray(['maxRuntime' => '600', 'redeliverTimeout' => '900']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): CommandTester
    {
        $tester = new CommandTester(new StatusCommand(
            $this->versionRepository,
            $this->get(FileIndexRepository::class),
            new LocalFilesystemStorage($this->settings),
            $this->settings
        ));
        $tester->execute($input, ['verbosity' => $verbosity]);

        return $tester;
    }

    private function createVersion(int $fileUid = 1, string $sha1 = self::SHA1): int
    {
        return (int)$this->versionRepository->create($fileUid, $sha1, $this->settings->createProfileSnapshot());
    }

    private function createReadyVersion(int $fileUid = 1, string $sha1 = self::SHA1): int
    {
        $uid = $this->createVersion($fileUid, $sha1);
        $this->versionRepository->claim($uid, 600);
        $this->versionRepository->markReady($uid, $fileUid . '/' . $uid);

        return $uid;
    }

    private function getOutput(CommandTester $tester): string
    {
        // Tables are wrapped to the terminal width
        return (string)preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testOverviewCountsVersionsAndListsRunningAndFailedOnes(): void
    {
        $this->createVersion(1);
        $processing = $this->createVersion(2, '2222222222222222222222222222222222222222');
        $this->versionRepository->claim($processing, 600);
        $failed = $this->createVersion(3, '3333333333333333333333333333333333333333');
        $this->versionRepository->claim($failed, 600);
        $this->versionRepository->markFailed($failed, 'ffmpeg exited with code 1');

        $tester = $this->execute();
        $output = $this->getOutput($tester);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('pending 1', $output);
        self::assertStringContainsString('processing 1', $output);
        self::assertStringContainsString('failed 1', $output);
        self::assertStringContainsString($processing . ' 2: 1:/videos/unreferenced.mp4', $output);
        self::assertStringContainsString('ffmpeg exited with code 1', $output);
    }

    public function testProcessingVersionAboveMaximumRuntimeIsMarked(): void
    {
        $uid = $this->createVersion();
        $this->versionRepository->claim($uid, 600);
        $this->get(ConnectionPool::class)->getConnectionForTable(VersionRepository::TABLE_NAME)
            ->update(VersionRepository::TABLE_NAME, ['processing_started_at' => time() - 3600], ['uid' => $uid]);

        self::assertStringContainsString('exceeds maximum runtime', $this->getOutput($this->execute()));
    }

    public function testSkippedVersionsAreListedOnlyVerbose(): void
    {
        $uid = $this->createVersion();
        $this->versionRepository->claim($uid, 600);
        $this->versionRepository->markSkipped($uid, 'Duration is below the minimum of 10 seconds.');

        self::assertStringNotContainsString('below the minimum', $this->getOutput($this->execute()));
        self::assertStringContainsString(
            'below the minimum',
            $this->getOutput($this->execute([], OutputInterface::VERBOSITY_VERBOSE))
        );
    }

    public function testFileShowsActiveVersionAndOutdatedContent(): void
    {
        $outdated = $this->createReadyVersion(1, 'ffffffffffffffffffffffffffffffffffffffff');
        $active = $this->createReadyVersion();

        $output = $this->getOutput($this->execute(['fileUid' => '1']));

        self::assertStringContainsString('1: 1:/videos/referenced.mp4', $output);
        self::assertStringContainsString($active . ' ready (active) current current', $output);
        self::assertStringContainsString($outdated . ' ready outdated current', $output);
        self::assertStringContainsString('/_videos/1/' . $active . '/master.m3u8', $output);
    }

    public function testFileWithoutVersions(): void
    {
        $tester = $this->execute(['fileUid' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No versions', $tester->getDisplay());
    }

    public function testUnknownFileFails(): void
    {
        self::assertSame(Command::FAILURE, $this->execute(['fileUid' => '999'])->getStatusCode());
    }

    public function testInvalidFileUidIsRejected(): void
    {
        self::assertSame(Command::INVALID, $this->execute(['fileUid' => 'abc'])->getStatusCode());
    }
}
