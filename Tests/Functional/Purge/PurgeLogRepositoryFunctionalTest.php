<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\Audit\PurgeLogDateRangeFilter;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogOutcomeFilter;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Audit\PurgeLogRow;
use Skom\Lscache\Purge\Audit\PurgeLogSiteFilter;
use Skom\Lscache\Purge\Audit\PurgeLogSourceFilter;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PurgeLogRepositoryFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function findByFiltersSupportsSiteSourceOutcomeDateRangeLimitAndOffset(): void
    {
        $this->seedRows();

        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);

        self::assertSame(3, $repository->countBySite('main'));

        $recent = $repository->findRecentBySite('main', 2, 1);
        self::assertCount(2, $recent);
        self::assertSame(['request-2', 'request-1'], array_map(
            static fn (PurgeLogRow $row): string => $row->requestId,
            $recent,
        ));

        $filtered = $repository->findByFilters(
            new PurgeLogSiteFilter('main'),
            new PurgeLogSourceFilter([PurgeSource::API]),
            new PurgeLogOutcomeFilter([PurgeLogOutcome::TRUNCATED]),
            new PurgeLogDateRangeFilter(
                new DateTimeImmutable('@250'),
                new DateTimeImmutable('@350'),
            ),
            10,
            0,
        );

        self::assertCount(1, $filtered);
        self::assertSame('request-3', $filtered[0]->requestId);
        self::assertSame(['main_page_3'], $filtered[0]->tags);
        self::assertSame(['https://example.org/3'], $filtered[0]->urls);
        self::assertSame(PurgeLogOutcome::TRUNCATED, $filtered[0]->outcome);
    }

    #[Test]
    public function purgeOlderThanDeletesOnlyRowsBeforeTheCutoff(): void
    {
        $this->seedRows();

        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);

        $deleted = $repository->purgeOlderThan(new DateTimeImmutable('@250'));

        self::assertSame(2, $deleted);
        self::assertSame(1, $repository->countBySite('main'));
        self::assertSame(['request-3'], array_map(
            static fn (PurgeLogRow $row): string => $row->requestId,
            $repository->findRecentBySite('main', 10, 0),
        ));
    }

    #[Test]
    public function legacyCacheFlushEventSourceValueIsMappedForHydration(): void
    {
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $repository->purgeOlderThan(new DateTimeImmutable('@32503680000'));

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_skomlscache_purge_log');
        $this->insertRow($connection, [
            'crdate' => 500,
            'site_identifier' => 'main',
            'source' => 'cache_flush_event',
            'outcome' => PurgeLogOutcome::ENQUEUED->value,
            'request_id' => 'request-legacy',
            'tags' => [],
            'urls' => [],
            'tag_count' => 0,
            'trigger_ref' => 'cache-flush-event:pages',
        ]);

        $rows = $repository->findRecentBySite('main', 10, 0);
        self::assertCount(1, $rows);
        self::assertSame(PurgeSource::CACHE_FLUSH_EVENT, $rows[0]->source);
        self::assertSame('request-legacy', $rows[0]->requestId);
    }

    private function seedRows(): void
    {
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $repository->purgeOlderThan(new DateTimeImmutable('@32503680000'));

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_skomlscache_purge_log');

        $this->insertRow($connection, [
            'crdate' => 100,
            'site_identifier' => 'main',
            'source' => PurgeSource::API->value,
            'outcome' => PurgeLogOutcome::ENQUEUED->value,
            'request_id' => 'request-1',
            'tags' => ['main_page_1'],
            'urls' => [],
            'tag_count' => 1,
            'trigger_ref' => 'trigger-1',
        ]);
        $this->insertRow($connection, [
            'crdate' => 200,
            'site_identifier' => 'main',
            'source' => PurgeSource::MODULE->value,
            'outcome' => PurgeLogOutcome::FLUSHED->value,
            'request_id' => 'request-2',
            'tags' => ['main_page_2'],
            'urls' => ['https://example.org/2'],
            'tag_count' => 1,
            'trigger_ref' => 'trigger-2',
        ]);
        $this->insertRow($connection, [
            'crdate' => 300,
            'site_identifier' => 'main',
            'source' => PurgeSource::API->value,
            'outcome' => PurgeLogOutcome::TRUNCATED->value,
            'request_id' => 'request-3',
            'tags' => ['main_page_3'],
            'urls' => ['https://example.org/3'],
            'tag_count' => 1,
            'trigger_ref' => 'trigger-3',
        ]);
        $this->insertRow($connection, [
            'crdate' => 400,
            'site_identifier' => 'other',
            'source' => PurgeSource::CLI->value,
            'outcome' => PurgeLogOutcome::SKIPPED->value,
            'request_id' => 'request-4',
            'tags' => ['other_page_1'],
            'urls' => [],
            'tag_count' => 1,
            'trigger_ref' => 'trigger-4',
        ]);
    }

    /**
     * @param array{
     *     crdate: int,
     *     site_identifier: string,
     *     source: string,
     *     outcome: string,
     *     request_id: string,
     *     tags: list<string>,
     *     urls: list<string>,
     *     tag_count: int,
     *     trigger_ref: ?string
     * } $row
     */
    private function insertRow(Connection $connection, array $row): void
    {
        $connection->insert('tx_skomlscache_purge_log', [
            'pid' => 0,
            'crdate' => $row['crdate'],
            'site_identifier' => $row['site_identifier'],
            'source' => $row['source'],
            'trigger_ref' => $row['trigger_ref'],
            'tags' => json_encode($row['tags'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'urls' => json_encode($row['urls'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tag_count' => $row['tag_count'],
            'outcome' => $row['outcome'],
            'latency_ms' => 0,
            'request_id' => $row['request_id'],
        ]);
    }
}
