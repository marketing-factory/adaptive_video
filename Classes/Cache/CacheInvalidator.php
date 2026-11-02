<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Cache;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Flushes the pages that render a file, so they pick up a new version or stop
 * pointing to the version of replaced content. A failure is only
 * logged; the cache lifetime is the fallback.
 */
class CacheInvalidator
{
    private const TAG_PREFIX = 'adaptivevideo_file_';

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getTag(int $fileUid): string
    {
        return self::TAG_PREFIX . $fileUid;
    }

    public function flush(int $fileUid): void
    {
        try {
            $this->cacheManager->flushCachesInGroupByTags('pages', [self::getTag($fileUid)]);
        } catch (\Throwable $e) {
            $this->logger->error('Flushing the page cache of file {fileUid} failed: {message}', [
                'fileUid' => $fileUid,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
