<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\EventListener\CacheFlushEventListener;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MultiSiteTagIsolationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
        $this->insertRootPage(2, '/site-b');
        $this->writeSiteConfig('siteA', true, 1, 'https://site-a.example.test/');
        $this->writeSiteConfig('siteB', false, 2, 'https://site-b.example.test/');
        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }

    #[Test]
    public function cacheFlushAffectsOnlyEnabledSiteAuditStream(): void
    {
        ($this->get(CacheFlushEventListener::class))(new CacheFlushEvent(['pages']));

        $repository = $this->get(PurgeLogRepository::class);

        self::assertSame(1, $repository->countBySite('siteA'));
        self::assertSame(0, $repository->countBySite('siteB'));

        $latestA = $repository->findNewestRequestIdByTrigger('siteA', PurgeSource::CACHE_FLUSH_EVENT, 'cache-flush-event:pages');
        $latestB = $repository->findNewestRequestIdByTrigger('siteB', PurgeSource::CACHE_FLUSH_EVENT, 'cache-flush-event:pages');

        self::assertNotNull($latestA);
        self::assertNull($latestB);
    }

    private function insertRootPage(int $uid, string $slug): void
    {
        $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid' => $uid,
            'pid' => 0,
            'title' => 'Root ' . $uid,
            'slug' => $slug,
            'doktype' => 1,
            'perms_everybody' => 15,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'deleted' => 0,
        ]);
    }

    private function writeSiteConfig(string $identifier, bool $enabled, int $rootPageId, string $base): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/' . $identifier;
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        $enabledFlag = $enabled ? 'true' : 'false';

        file_put_contents(
            $sitePath . '/config.yaml',
            <<<YAML
base: '{$base}'
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
rootPageId: {$rootPageId}
settings:
  lscache:
    enabled: {$enabledFlag}
    defaultTtl: 300
    tagNamespace: '{$identifier}'
    mirror:
      cacheFlushEvent: true
    loopback:
      secret: 'test-secret'
      rateLimit: 120
websiteTitle: ''
YAML,
        );
    }
}
