<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Audit\PurgeLogRow;
use Skom\Lscache\Purge\Audit\PurgeLogRetentionTask;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PurgeLogRetentionTaskFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
    }

    #[Test]
    public function executeDeletesRowsOlderThanTheConfiguredRetentionWindow(): void
    {
        $this->writeSiteConfiguration(3);
        $this->seedRows(4);

        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $task = new PurgeLogRetentionTask($repository, $this->get(SiteFinder::class));

        self::assertSame('Purges audit rows older than 3 days.', $task->getAdditionalInformation());
        self::assertTrue($task->execute());
        self::assertSame(1, $repository->countBySite('main'));
        self::assertSame(['request-new'], array_map(
            static fn (PurgeLogRow $row): string => $row->requestId,
            $repository->findRecentBySite('main', 10, 0),
        ));
    }

    #[Test]
    public function executeDefaultsToSevenDaysWhenNoRetentionSettingIsConfigured(): void
    {
        $this->writeSiteConfiguration(null);
        $this->seedRows(8);

        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $task = new PurgeLogRetentionTask($repository, $this->get(SiteFinder::class));

        self::assertStringContainsString('7 days', $task->getAdditionalInformation());
        self::assertTrue($task->execute());
        self::assertSame(1, $repository->countBySite('main'));
        self::assertSame(['request-new'], array_map(
            static fn (PurgeLogRow $row): string => $row->requestId,
            $repository->findRecentBySite('main', 10, 0),
        ));
    }

    #[Test]
    public function executeAppliesRetentionPerConfiguredSiteAndDefaultForUnknownSites(): void
    {
        $this->writeSiteConfiguration(3, 'main', 'https://example.test/');
        $this->writeSiteConfiguration(10, 'other', 'https://other.test/');

        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $repository->purgeOlderThan(new \DateTimeImmutable('@32503680000'));

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_skomlscache_purge_log');
        $now = time();

        $this->insertRow($connection, [
            'crdate' => $now - (4 * 86400),
            'site_identifier' => 'main',
            'request_id' => 'main-old',
        ]);
        $this->insertRow($connection, [
            'crdate' => $now - 86400,
            'site_identifier' => 'main',
            'request_id' => 'main-new',
        ]);
        $this->insertRow($connection, [
            'crdate' => $now - (4 * 86400),
            'site_identifier' => 'other',
            'request_id' => 'other-old',
        ]);
        $this->insertRow($connection, [
            'crdate' => $now - 86400,
            'site_identifier' => 'other',
            'request_id' => 'other-new',
        ]);
        $this->insertRow($connection, [
            'crdate' => $now - (8 * 86400),
            'site_identifier' => 'unknown',
            'request_id' => 'unknown-old',
        ]);
        $this->insertRow($connection, [
            'crdate' => $now - 86400,
            'site_identifier' => 'unknown',
            'request_id' => 'unknown-new',
        ]);

        $task = new PurgeLogRetentionTask($repository, $this->get(SiteFinder::class));

        self::assertStringContainsString('per-site retentionDays', $task->getAdditionalInformation());
        self::assertTrue($task->execute());

        self::assertSame(['main-new'], array_map(
            static fn (PurgeLogRow $row): string => $row->requestId,
            $repository->findRecentBySite('main', 10, 0),
        ));
        self::assertSame(['other-new', 'other-old'], array_map(
            static fn (PurgeLogRow $row): string => $row->requestId,
            $repository->findRecentBySite('other', 10, 0),
        ));
        self::assertSame(['unknown-new'], array_map(
            static fn (PurgeLogRow $row): string => $row->requestId,
            $repository->findRecentBySite('unknown', 10, 0),
        ));
    }

    private function seedRows(int $daysOld): void
    {
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $repository->purgeOlderThan(new \DateTimeImmutable('@32503680000'));

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_skomlscache_purge_log');
        $now = time();

        $this->insertRow($connection, [
            'crdate' => $now - ($daysOld * 86400),
            'site_identifier' => 'main',
            'request_id' => 'request-old',
        ]);
        $this->insertRow($connection, [
            'crdate' => $now - 86400,
            'site_identifier' => 'main',
            'request_id' => 'request-new',
        ]);
    }

    /**
     * @param Connection $connection
     * @param array{crdate: int, site_identifier: string, request_id: string} $row
     */
    private function insertRow(Connection $connection, array $row): void
    {
        $connection->insert('tx_skomlscache_purge_log', [
            'pid' => 0,
            'crdate' => $row['crdate'],
            'site_identifier' => $row['site_identifier'],
            'source' => PurgeSource::API->value,
            'trigger_ref' => 'retention-test',
            'tags' => json_encode(['main_page_1'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'urls' => json_encode([], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tag_count' => 1,
            'outcome' => PurgeLogOutcome::ENQUEUED->value,
            'latency_ms' => 0,
            'request_id' => $row['request_id'],
        ]);
    }

    private function writeSiteConfiguration(?int $retentionDays, string $siteIdentifier = 'main', string $base = 'https://example.test/'): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/' . $siteIdentifier;
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        $settings = '';
        if ($retentionDays !== null) {
            $settings = <<<YAML
settings:
  lscache:
    defaultTtl: 300
    loopback:
      rateLimit: 120
    audit:
      retentionDays: {$retentionDays}
YAML;
        }

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
rootPageId: 1
{$settings}
websiteTitle: ''
YAML,
        );

        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }
}
