<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Processing;

use Mfd\AdaptiveVideo\Processing\EncodingProfile;
use Mfd\AdaptiveVideo\Processing\ProfileSnapshot;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ProfileSnapshotTest extends UnitTestCase
{
    private function createProfile(array $overrides = []): EncodingProfile
    {
        return EncodingProfile::fromArray('default', $overrides + EncodingProfile::DEFAULT_CONFIGURATION);
    }

    public function testFingerprintIsSha256OfTheSnapshot(): void
    {
        $subject = ProfileSnapshot::create($this->createProfile(), []);

        self::assertSame(hash('sha256', $subject->toJson()), $subject->getFingerprint());
        self::assertSame(64, strlen($subject->getFingerprint()));
    }

    public function testSameProfileYieldsSameFingerprint(): void
    {
        self::assertSame(
            ProfileSnapshot::create($this->createProfile(), ['-preset', 'slow'])->getFingerprint(),
            ProfileSnapshot::create($this->createProfile(), ['-preset', 'slow'])->getFingerprint()
        );
    }

    public function testChangedEncodingParameterChangesFingerprint(): void
    {
        self::assertNotSame(
            ProfileSnapshot::create($this->createProfile(), [])->getFingerprint(),
            ProfileSnapshot::create($this->createProfile(['audioBitrate' => 96]), [])->getFingerprint()
        );
    }

    public function testResultRelevantFfmpegOptionsChangeFingerprint(): void
    {
        self::assertNotSame(
            ProfileSnapshot::create($this->createProfile(), [])->getFingerprint(),
            ProfileSnapshot::create($this->createProfile(), ['-preset', 'slow'])->getFingerprint()
        );
    }

    public function testThreadsOptionDoesNotChangeFingerprint(): void
    {
        $withoutThreads = ProfileSnapshot::create($this->createProfile(), ['-preset', 'slow']);
        $withThreads = ProfileSnapshot::create($this->createProfile(), ['-threads', '4', '-preset', 'slow']);

        self::assertSame($withoutThreads->getFingerprint(), $withThreads->getFingerprint());
        self::assertSame(['-preset', 'slow'], $withThreads->data['ffmpegOptions']);
    }

    public function testFingerprintDoesNotDependOnConfigurationKeyOrder(): void
    {
        $configuration = EncodingProfile::DEFAULT_CONFIGURATION;
        $reordered = array_reverse($configuration, true);
        $reordered['renditions'] = array_map(
            static fn(array $rendition): array => array_reverse($rendition, true),
            $reordered['renditions']
        );

        self::assertSame(
            ProfileSnapshot::create(EncodingProfile::fromArray('default', $configuration), [])->getFingerprint(),
            ProfileSnapshot::create(EncodingProfile::fromArray('default', $reordered), [])->getFingerprint()
        );
    }

    public function testFromJsonRestoresFingerprint(): void
    {
        $subject = ProfileSnapshot::create($this->createProfile(), ['-preset', 'slow']);

        self::assertSame($subject->getFingerprint(), ProfileSnapshot::fromJson($subject->toJson())->getFingerprint());
    }

    public function testFromJsonRejectsNonObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1793000010);

        ProfileSnapshot::fromJson('"foo"');
    }
}
