<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Must run against MySQL/MariaDB: version creation and reservation rely on
 * the unique index and on the affected row count of conditional updates.
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'filemetadata',
    ];

    protected array $testExtensionsToLoad = [
        'b13/poster-image',
        'mfd/adaptive-video',
    ];
}
