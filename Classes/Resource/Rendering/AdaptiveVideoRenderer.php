<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Resource\Rendering;

use Mfd\AdaptiveVideo\Cache\CacheInvalidator;
use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Rendering\StreamResolver;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\Rendering\VideoTagRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * Delivers videos with an active version as HLS and keeps the original as
 * <source>, used without JavaScript or if the stream fails. It replaces the
 * renderer of b13/poster-image and therefore adds the poster itself (§8).
 */
class AdaptiveVideoRenderer extends VideoTagRenderer
{
    private const SCRIPT = 'EXT:adaptive_video/Resources/Public/JavaScript/adaptive-video.js';

    public function __construct(
        private readonly StreamResolver $streamResolver,
        private readonly ExtensionSettings $settings,
        private readonly LinkService $linkService,
        private readonly AssetCollector $assetCollector,
    ) {
    }

    public function getPriority()
    {
        return $this->settings->rendererPriority;
    }

    public function canRender(FileInterface $file)
    {
        if (!$this->settings->deliveryEnabled
            || !$this->getSiteSettings()?->get('adaptiveVideo.enabled', false)
            || !$this->settings->isSupportedFileExtension($file->getExtension())
        ) {
            return false;
        }

        // E.g. a .mov file is only taken over once it has a stream
        return parent::canRender($file) || $this->streamResolver->getPlaylistUrl($file) !== null;
    }

    public function render(FileInterface $file, $width, $height, array $options = [])
    {
        // Also for the original: the page must be flushed once a version is ready
        $this->addCacheTag($file);
        $options = $this->addPoster($file, $options);

        $siteSettings = $this->getSiteSettings();
        if (empty($options['preload'])) {
            $options['preload'] = (string)$siteSettings?->get('adaptiveVideo.preload', '');
        }

        $playlistUrl = $this->streamResolver->getPlaylistUrl($file);
        if ($playlistUrl === null) {
            return parent::render($file, $width, $height, $options);
        }

        // The script applies autoplay and preload, so the browser does not load the original first
        $autoplay = $options['autoplay'] ?? ($file instanceof FileReference ? $file->getProperty('autoplay') : false);
        $options['data'] = array_merge($options['data'] ?? [], [
            'adaptive-video-src' => $playlistUrl,
            'adaptive-video-preload' => (string)$options['preload'],
            'adaptive-video-cap-level' => $siteSettings?->get('adaptiveVideo.capLevelToPlayerSize', true) ? '1' : '0',
        ]);
        if ($autoplay) {
            $options['data']['adaptive-video-autoplay'] = '1';
            // Browsers only autoplay muted videos
            $options['muted'] = true;
        }

        $options['autoplay'] = false;
        $options['preload'] = 'none';

        $this->assetCollector->addJavaScript('adaptive-video', self::SCRIPT, ['type' => 'module']);

        return parent::render($file, $width, $height, $options);
    }

    /**
     * A poster from the template wins over sys_file_metadata.poster of
     * b13/poster-image (a FAL link).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function addPoster(FileInterface $file, array $options): array
    {
        $posterReference = $file->getProperty('poster');
        if (!empty($options['additionalAttributes']['poster']) || !$posterReference) {
            return $options;
        }

        $poster = $posterReference instanceof FileInterface
            ? $posterReference
            : ($this->linkService->resolve((string)$posterReference)['file'] ?? null);
        $posterUrl = $poster instanceof FileInterface ? $poster->getPublicUrl() : null;
        if ($posterUrl) {
            $options['additionalAttributes']['poster'] = $posterUrl;
        }

        return $options;
    }

    private function addCacheTag(FileInterface $file): void
    {
        $file = $this->streamResolver->getOriginalFile($file);
        $collector = $this->getRequest()?->getAttribute('frontend.cache.collector');
        if ($file !== null && $collector instanceof CacheDataCollector) {
            $collector->addCacheTags(new CacheTag(CacheInvalidator::getTag($file->getUid())));
        }
    }

    /**
     * Settings of the current site; null outside a site, e.g. on the CLI.
     */
    private function getSiteSettings(): ?SiteSettings
    {
        $site = $this->getRequest()?->getAttribute('site');

        return $site instanceof Site ? $site->getSettings() : null;
    }

    /**
     * FileRendererInterface::render() does not receive the request.
     */
    private function getRequest(): ?ServerRequestInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface ? $request : null;
    }
}
