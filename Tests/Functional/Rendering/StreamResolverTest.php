<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Rendering;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Rendering\StreamResolver;
use Mfd\AdaptiveVideo\Storage\LocalFilesystemStorage;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class StreamResolverTest extends AbstractFunctionalTestCase
{
    private const SHA1 = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    private VersionRepository $versionRepository;
    private ExtensionSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Storage.csv');
        $this->versionRepository = new VersionRepository($this->get(ConnectionPool::class));
        $this->settings = ExtensionSettings::fromArray([]);
    }

    private function createFile(int $uid, string $extension = 'mp4', string $sha1 = self::SHA1): File
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => $uid,
            'storage' => 1,
            'identifier' => '/videos/source' . $uid . '.' . $extension,
            'name' => 'source' . $uid . '.' . $extension,
            'extension' => $extension,
            'mime_type' => 'video/mp4',
            'sha1' => $sha1,
        ]);

        return $this->get(ResourceFactory::class)->getFileObject($uid);
    }

    private function createReadyVersion(int $fileUid, string $sha1 = self::SHA1): int
    {
        $uid = (int)$this->versionRepository->create($fileUid, $sha1, $this->settings->createProfileSnapshot());
        $this->versionRepository->claim($uid, 600);
        $this->versionRepository->markReady($uid, $fileUid . '/' . $uid);

        return $uid;
    }

    private function createSubject(): StreamResolver
    {
        return new StreamResolver($this->versionRepository, new LocalFilesystemStorage($this->settings), $this->settings);
    }

    public function testPlaylistOfNewestReadyVersionIsReturned(): void
    {
        $file = $this->createFile(1);
        $this->createReadyVersion(1);
        // Same content, newer profile
        $this->settings = ExtensionSettings::fromArray(['ffmpegOptions' => '-threads 1 -preset slow']);
        $newestUid = $this->createReadyVersion(1);

        self::assertSame('/_videos/1/' . $newestUid . '/master.m3u8', $this->createSubject()->getPlaylistUrl($file));
    }

    public function testPendingVersionIsNotDelivered(): void
    {
        $file = $this->createFile(1);
        $this->versionRepository->create(1, self::SHA1, $this->settings->createProfileSnapshot());

        self::assertNull($this->createSubject()->getPlaylistUrl($file));
    }

    public function testVersionOfOtherContentIsNotDelivered(): void
    {
        $file = $this->createFile(1, sha1: sha1('replaced content'));
        $this->createReadyVersion(1);

        self::assertNull($this->createSubject()->getPlaylistUrl($file));
    }

    public function testUnsupportedFileIsNotDelivered(): void
    {
        $file = $this->createFile(1, 'ogv');
        $this->createReadyVersion(1);

        self::assertNull($this->createSubject()->getPlaylistUrl($file));
    }
}
