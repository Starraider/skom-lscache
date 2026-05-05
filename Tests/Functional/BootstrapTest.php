<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BootstrapTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function extensionLoadsCleanlyIntoTestInstance(): void
    {
        self::assertTrue(
            ExtensionManagementUtility::isLoaded('skom_lscache'),
            'Extension skom_lscache must be active in the TYPO3 test instance',
        );
    }
}
