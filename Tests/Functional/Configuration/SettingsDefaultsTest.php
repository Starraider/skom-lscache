<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SettingsDefaultsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->writeSiteConfiguration();
    }

    #[Test]
    public function definitionsContainAllExpectedTypedSettingKeys(): void
    {
        $definitionsFile = __DIR__ . '/../../../Configuration/Sets/Lscache/settings.definitions.yaml';
        $yaml = Yaml::parseFile($definitionsFile);

        $expectedKeys = [
            'lscache.enabled',
            'lscache.defaultTtl',
            'lscache.tagNamespace',
            'lscache.autopurge.dataHandler',
            'lscache.autopurge.tables',
            'lscache.mirror.cacheFlushEvent',
            'lscache.bypass.loggedInFE',
            'lscache.bypass.setCookie',
            'lscache.bypass.paths',
            'lscache.bypass.pluginSignatures',
            'lscache.bypass.ctypes',
            'lscache.vary.cookieAllowlist',
            'lscache.loopback.secret',
            'lscache.loopback.rateLimit',
            'lscache.loopback.allowedRemoteAddrs',
            'lscache.probe.allowedHosts',
            'lscache.audit.retentionDays',
        ];

        self::assertSame($expectedKeys, array_keys($yaml['settings']));
    }

    #[Test]
    public function siteSettingsResolveExpectedSafeDefaultsAtRuntime(): void
    {
        $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId(1);
        $settings = $site->getSettings();

        self::assertTrue($settings->get('lscache.enabled'));
        self::assertSame(3600, $settings->get('lscache.defaultTtl'));
        self::assertSame('', $settings->get('lscache.tagNamespace'));
        self::assertTrue($settings->get('lscache.autopurge.dataHandler'));
        self::assertFalse($settings->get('lscache.mirror.cacheFlushEvent'));
        self::assertTrue($settings->get('lscache.bypass.loggedInFE'));
        self::assertTrue($settings->get('lscache.bypass.setCookie'));
        self::assertSame([], $settings->get('lscache.bypass.paths'));
        self::assertSame([], $settings->get('lscache.bypass.pluginSignatures'));
        self::assertSame([], $settings->get('lscache.bypass.ctypes'));
        self::assertSame([], $settings->get('lscache.vary.cookieAllowlist'));
        self::assertSame(120, $settings->get('lscache.loopback.rateLimit'));
        self::assertSame(['127.0.0.1', '::1'], $settings->get('lscache.loopback.allowedRemoteAddrs'));
        self::assertSame([], $settings->get('lscache.probe.allowedHosts'));
        self::assertSame(7, $settings->get('lscache.audit.retentionDays'));
    }

    private function writeSiteConfiguration(): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/main';
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        file_put_contents(
            $sitePath . '/config.yaml',
            <<<'YAML'
base: 'https://example.test/'
dependencies:
  - skom/lscache/default
languages:
  -
    title: Deutsch
    enabled: true
    languageId: 0
    base: /
    locale: de_DE.utf8
    navigationTitle: Deutsch
    flag: de
    hreflang: de-DE
    websiteTitle: ''
rootPageId: 1
websiteTitle: ''
YAML,
        );
    }
}
