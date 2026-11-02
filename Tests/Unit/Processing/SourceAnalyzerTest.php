<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Processing;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Processing\SourceAnalyzer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SourceAnalyzerTest extends UnitTestCase
{
    public function testCommandUsesPrefixBinaryAndFileProtocol(): void
    {
        $subject = new SourceAnalyzer(ExtensionSettings::fromArray([
            'ffprobePath' => '/usr/bin/ffprobe',
            'commandPrefix' => 'nice -n 19',
        ]));

        self::assertSame(
            ['nice', '-n', '19', '/usr/bin/ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', 'file:/var/www/fileadmin/a b.mp4'],
            $subject->buildCommand('/var/www/fileadmin/a b.mp4')
        );
    }

    public function testCommandWithoutPrefix(): void
    {
        $subject = new SourceAnalyzer(ExtensionSettings::fromArray(['commandPrefix' => '']));

        self::assertSame('ffprobe', $subject->buildCommand('/tmp/video.mp4')[0]);
    }
}
