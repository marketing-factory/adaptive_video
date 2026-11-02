<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\EventListener;

use Mfd\AdaptiveVideo\Cache\CacheInvalidator;
use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;

/**
 * Cached pages contain versioned playlist URLs and would keep delivering the
 * old content after a replacement (§3). Re-encoding is left to the next scan.
 */
final readonly class FileReplacedListener
{
    public function __construct(
        private CacheInvalidator $cacheInvalidator,
        private ExtensionSettings $settings,
    ) {
    }

    #[AsEventListener('adaptive-video/file-replaced')]
    public function __invoke(AfterFileReplacedEvent $event): void
    {
        $file = $event->getFile();
        if ($file instanceof File && $this->settings->isSupportedFileExtension($file->getExtension())) {
            $this->cacheInvalidator->flush($file->getUid());
        }
    }
}
