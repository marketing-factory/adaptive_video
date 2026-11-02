<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Cache;

use Mfd\AdaptiveVideo\Cache\CacheInvalidator;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CacheInvalidatorTest extends UnitTestCase
{
    public function testPagesAreFlushedByFileTag(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::once())->method('flushCachesInGroupByTags')->with('pages', ['adaptivevideo_file_42']);

        (new CacheInvalidator($cacheManager, $this->createStub(LoggerInterface::class)))->flush(42);
    }

    public function testFailureIsOnlyLogged(): void
    {
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('flushCachesInGroupByTags')->willThrowException(new \RuntimeException('Redis is gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new CacheInvalidator($cacheManager, $logger))->flush(42);
    }
}
