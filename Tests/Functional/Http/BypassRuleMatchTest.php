<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Http\BypassReason;
use Skom\Lscache\Http\CacheabilityClassifierInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BypassRuleMatchTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function fnmatchBypassRuleMarksRequestNonCacheableWithRuleMatchReason(): void
    {
        $decision = $this->get(CacheabilityClassifierInterface::class)->classify(
            $this->createRequest(),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::BYPASS_PATH, $decision->reason);
        self::assertSame('/preview/*', $decision->matchedRule);
    }

    private function createRequest(): ServerRequest
    {
        $site = new Site(
            'main',
            1,
            ['base' => 'https://example.org/', 'languages' => [[
                'languageId' => 0,
                'base' => '/',
                'title' => 'Default',
                'navigationTitle' => 'Default',
                'flag' => 'de',
                'locale' => 'de_DE.UTF-8',
            ]]],
            SiteSettings::createFromSettingsTree([
                'lscache' => [
                    'defaultTtl' => 300,
                    'tagNamespace' => 'main',
                    'bypass' => [
                        'paths' => ['/preview/*'],
                    ],
                ],
            ]),
        );

        return (new ServerRequest(new Uri('https://example.org/preview/page-1'), 'GET', null, [], [
            'SERVER_SOFTWARE' => 'OpenLiteSpeed',
        ]))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('routing', new PageArguments(42, '0', []));
    }
}
