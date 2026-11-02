<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Storage;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\Exception\ProcessingException;
use Mfd\AdaptiveVideo\Storage\LocalFilesystemStorage;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;

final class LocalFilesystemStorageTest extends AbstractFunctionalTestCase
{
    private LocalFilesystemStorage $subject;
    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new LocalFilesystemStorage(ExtensionSettings::fromArray([]));
        $this->artifactDirectory = $this->instancePath . '/_videos';
        (new Filesystem())->remove($this->artifactDirectory);
    }

    private function createOutput(string $baseDirectory, string $content = 'playlist'): string
    {
        $directory = $baseDirectory . '/output_' . bin2hex(random_bytes(4));
        mkdir($directory . '/720p', 0777, true);
        file_put_contents($directory . '/master.m3u8', $content);
        file_put_contents($directory . '/720p/segment_00000.m4s', 'data');

        return $directory;
    }

    public function testPublishMovesTheDirectoryToThePrefix(): void
    {
        $output = $this->createOutput($this->instancePath . '/typo3temp/var/transient');

        $this->subject->publish($output, '12/34');

        self::assertFileExists($this->artifactDirectory . '/12/34/master.m3u8');
        self::assertFileExists($this->artifactDirectory . '/12/34/720p/segment_00000.m4s');
        self::assertDirectoryDoesNotExist($output);
    }

    public function testPublishFromAnotherFilesystemCopiesWithoutLeftovers(): void
    {
        $output = $this->createOutput(sys_get_temp_dir());

        $this->subject->publish($output, '12/34');

        self::assertFileExists($this->artifactDirectory . '/12/34/720p/segment_00000.m4s');
        self::assertDirectoryDoesNotExist($output);
        self::assertSame(['34'], array_values(array_diff((array)scandir($this->artifactDirectory . '/12'), ['.', '..'])));
    }

    public function testPublishReplacesTheOutputOfAnAbortedAttempt(): void
    {
        $this->subject->publish($this->createOutput($this->instancePath . '/typo3temp/var/transient', 'old'), '12/34');
        file_put_contents($this->artifactDirectory . '/12/34/stale.m4s', 'stale');

        $this->subject->publish($this->createOutput($this->instancePath . '/typo3temp/var/transient', 'new'), '12/34');

        self::assertStringEqualsFile($this->artifactDirectory . '/12/34/master.m3u8', 'new');
        self::assertFileDoesNotExist($this->artifactDirectory . '/12/34/stale.m4s');
    }

    public function testDeleteRemovesThePrefix(): void
    {
        $this->subject->publish($this->createOutput($this->instancePath . '/typo3temp/var/transient'), '12/34');

        $this->subject->delete('12/34');

        self::assertDirectoryDoesNotExist($this->artifactDirectory . '/12/34');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPrefixDataProvider(): array
    {
        return [
            'empty' => [''],
            'parent directory' => ['../12'],
            'parent directory inside' => ['12/../34'],
            'absolute' => ['/12/34'],
            'trailing slash' => ['12/'],
        ];
    }

    #[DataProvider('invalidPrefixDataProvider')]
    public function testInvalidPrefixIsRejected(string $prefix): void
    {
        $this->expectException(ProcessingException::class);
        $this->expectExceptionCode(1793000070);

        $this->subject->delete($prefix);
    }

    public function testPublicUrlUsesThePrefix(): void
    {
        self::assertSame('/_videos/12/34/master.m3u8', $this->subject->getPublicUrl('12/34/master.m3u8'));
    }
}
