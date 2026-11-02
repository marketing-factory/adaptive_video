<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\EventListener;

use Mfd\AdaptiveVideo\Cache\CacheInvalidator;
use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\EventListener\FileReplacedListener;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FileReplacedListenerTest extends UnitTestCase
{
    private function createEvent(string $extension): AfterFileReplacedEvent
    {
        $file = $this->createStub(File::class);
        $file->method('getUid')->willReturn(42);
        $file->method('getExtension')->willReturn($extension);

        return new AfterFileReplacedEvent($file, '/tmp/upload');
    }

    public function testReplacedVideoFlushesItsPages(): void
    {
        $cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $cacheInvalidator->expects(self::once())->method('flush')->with(42);

        (new FileReplacedListener($cacheInvalidator, ExtensionSettings::fromArray([])))($this->createEvent('mp4'));
    }

    public function testOtherFilesAreIgnored(): void
    {
        $cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $cacheInvalidator->expects(self::never())->method('flush');

        (new FileReplacedListener($cacheInvalidator, ExtensionSettings::fromArray([])))($this->createEvent('pdf'));
    }
}
