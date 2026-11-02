<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Resource\Rendering;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Rendering\StreamResolver;
use Mfd\AdaptiveVideo\Resource\Rendering\AdaptiveVideoRenderer;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AdaptiveVideoRendererTest extends UnitTestCase
{
    private const PLAYLIST_URL = '/_videos/1/7/master.m3u8';

    private AssetCollector $assetCollector;
    private CacheDataCollector $cacheDataCollector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assetCollector = new AssetCollector();
        $this->cacheDataCollector = new CacheDataCollector();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $siteSettings null for a request without site
     */
    private function setRequest(?array $siteSettings = ['enabled' => true]): void
    {
        $request = (new ServerRequest('https://example.com/'))
            ->withAttribute('frontend.cache.collector', $this->cacheDataCollector);
        if ($siteSettings !== null) {
            $site = new Site('main', 1, [], SiteSettings::createFromSettingsTree(['adaptiveVideo' => $siteSettings]));
            $request = $request->withAttribute('site', $site);
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function createFile(string $extension = 'mp4', string $mimeType = 'video/mp4', array $properties = []): File
    {
        $file = $this->createStub(File::class);
        $file->method('getUid')->willReturn(1);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getMimeType')->willReturn($mimeType);
        $file->method('getPublicUrl')->willReturn('/fileadmin/videos/source.' . $extension);
        $file->method('getProperty')->willReturnCallback(static fn(string $key): mixed => $properties[$key] ?? null);

        return $file;
    }

    /**
     * @param array<string, string> $configuration
     */
    private function createSubject(?string $playlistUrl, ?LinkService $linkService = null, array $configuration = []): AdaptiveVideoRenderer
    {
        $streamResolver = $this->createStub(StreamResolver::class);
        $streamResolver->method('getPlaylistUrl')->willReturn($playlistUrl);
        $streamResolver->method('getOriginalFile')->willReturnArgument(0);

        return new AdaptiveVideoRenderer(
            $streamResolver,
            ExtensionSettings::fromArray($configuration),
            $linkService ?? $this->createStub(LinkService::class),
            $this->assetCollector
        );
    }

    public function testPriorityIsAboveB13AndCoreRenderer(): void
    {
        self::assertSame(20, $this->createSubject(null)->getPriority());
    }

    public function testDoesNotRenderWithoutSite(): void
    {
        $this->setRequest(null);

        self::assertFalse($this->createSubject(self::PLAYLIST_URL)->canRender($this->createFile()));
    }

    public function testDoesNotRenderIfDisabledForSite(): void
    {
        $this->setRequest(['enabled' => false]);

        self::assertFalse($this->createSubject(self::PLAYLIST_URL)->canRender($this->createFile()));
    }

    public function testDoesNotRenderIfDeliveryIsDisabledGlobally(): void
    {
        $this->setRequest();

        self::assertFalse($this->createSubject(self::PLAYLIST_URL, configuration: ['deliveryEnabled' => '0'])->canRender($this->createFile()));
    }

    public function testRendersSupportedVideoWithoutStream(): void
    {
        $this->setRequest();

        self::assertTrue($this->createSubject(null)->canRender($this->createFile()));
    }

    public function testRendersFileTypeUnknownToCoreOnlyWithStream(): void
    {
        $this->setRequest();
        $file = $this->createFile('mov', 'video/quicktime');

        self::assertFalse($this->createSubject(null)->canRender($file));
        self::assertTrue($this->createSubject(self::PLAYLIST_URL)->canRender($file));
    }

    public function testDoesNotRenderUnsupportedExtension(): void
    {
        $this->setRequest();

        self::assertFalse($this->createSubject(self::PLAYLIST_URL)->canRender($this->createFile('ogv', 'video/ogg')));
    }

    public function testOriginalIsRenderedWithPosterAndPreloadWithoutStream(): void
    {
        $this->setRequest(['enabled' => true, 'preload' => 'metadata']);
        $poster = $this->createStub(File::class);
        $poster->method('getPublicUrl')->willReturn('/fileadmin/poster.jpg');
        $linkService = $this->createStub(LinkService::class);
        $linkService->method('resolve')->willReturn(['type' => 'file', 'file' => $poster]);

        $html = $this->createSubject(null, $linkService)->render($this->createFile(properties: ['poster' => 't3://file?uid=2']), 0, 0);

        self::assertStringContainsString('poster="/fileadmin/poster.jpg"', $html);
        self::assertStringContainsString('preload="metadata"', $html);
        self::assertStringContainsString('<source src="/fileadmin/videos/source.mp4" type="video/mp4">', $html);
        self::assertStringNotContainsString('data-adaptive-video-src', $html);
        self::assertSame([], $this->assetCollector->getJavaScripts());
    }

    public function testPosterFromTemplateWins(): void
    {
        $this->setRequest();

        $html = $this->createSubject(null)->render(
            $this->createFile(properties: ['poster' => 't3://file?uid=2']),
            0,
            0,
            ['additionalAttributes' => ['poster' => '/template-poster.jpg']]
        );

        self::assertStringContainsString('poster="/template-poster.jpg"', $html);
    }

    public function testStreamDefersAutoplayAndPreloadToScript(): void
    {
        $this->setRequest(['enabled' => true, 'preload' => 'auto', 'capLevelToPlayerSize' => false]);

        $html = $this->createSubject(self::PLAYLIST_URL)->render($this->createFile(), 0, 0, ['autoplay' => 1]);

        self::assertStringContainsString('data-adaptive-video-src="' . self::PLAYLIST_URL . '"', $html);
        self::assertStringContainsString('data-adaptive-video-preload="auto"', $html);
        self::assertStringContainsString('data-adaptive-video-autoplay="1"', $html);
        self::assertStringContainsString('data-adaptive-video-cap-level="0"', $html);
        self::assertStringContainsString('preload="none"', $html);
        self::assertStringContainsString(' muted', $html);
        self::assertStringNotContainsString(' autoplay', $html);
        // The original stays as fallback
        self::assertStringContainsString('<source src="/fileadmin/videos/source.mp4" type="video/mp4">', $html);
        self::assertArrayHasKey('adaptive-video', $this->assetCollector->getJavaScripts());
    }

    public function testCacheTagIsAddedForOriginalAndStream(): void
    {
        $this->setRequest();

        $this->createSubject(null)->render($this->createFile(), 0, 0);

        self::assertSame(['adaptivevideo_file_1'], array_column($this->cacheDataCollector->getCacheTags(), 'name'));
    }
}
