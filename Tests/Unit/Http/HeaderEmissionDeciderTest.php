<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Http\HeaderEmissionDecider;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class HeaderEmissionDeciderTest extends TestCase
{
    #[Test]
    public function emissionCanBeDisabledPerSite(): void
    {
        $decider = new HeaderEmissionDecider();

        $siteA = SiteSettings::createFromSettingsTree(['lscache' => ['enabled' => false]]);
        $siteB = SiteSettings::createFromSettingsTree(['lscache' => ['enabled' => true]]);

        self::assertFalse($decider->shouldEmitForSite($siteA));
        self::assertTrue($decider->shouldEmitForSite($siteB));
    }

    #[Test]
    public function defaultsToEnabledWhenSettingIsMissing(): void
    {
        $decider = new HeaderEmissionDecider();
        $settings = SiteSettings::createFromSettingsTree([]);

        self::assertTrue($decider->shouldEmitForSite($settings));
    }
}
