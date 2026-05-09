<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

use DateTimeInterface;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\Event\PurgeIssuedEvent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Persistence layer for purge audit rows.
 *
 * @internal
 */
final readonly class PurgeLogRepository
{
    private const string TABLE = 'tx_skomlscache_purge_log';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function insertEnqueuedEvent(PurgeIssuedEvent $event, string $siteIdentifier, int $latencyMs = 0): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->insert(self::TABLE, [
                'pid' => 0,
                'crdate' => $event->getTimestamp(),
                'site_identifier' => $this->normalizeSiteIdentifier($siteIdentifier),
                'source' => $event->getSource()->value,
                'trigger_ref' => $event->getTriggerRef(),
                'tags' => $this->encodeList($event->getTags()),
                'urls' => $this->encodeList($event->getUrls()),
                'tag_count' => count($event->getTags()),
                'outcome' => PurgeLogOutcome::ENQUEUED->value,
                'latency_ms' => max(0, $latencyMs),
                'request_id' => $event->getRequestId(),
            ]);
    }

    public function countBySite(string $siteIdentifier): int
    {
        if (trim($siteIdentifier) === '') {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder();
        $count = $queryBuilder
            ->count('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count;
    }

    public function countBySiteSince(string $siteIdentifier, DateTimeInterface $since): int
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder();
        $count = $queryBuilder
            ->count('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since->getTimestamp(), Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count;
    }

    /**
     * @return list<PurgeLogRow>
     */
    public function findRecentBySite(string $siteIdentifier, int $limit, int $offset): array
    {
        return $this->findByFilters(
            new PurgeLogSiteFilter($siteIdentifier),
            new PurgeLogSourceFilter(),
            new PurgeLogOutcomeFilter(),
            new PurgeLogDateRangeFilter(),
            $limit,
            $offset,
        );
    }

    /**
     * @return list<PurgeLogRow>
     */
    public function findByFilters(
        PurgeLogSiteFilter $siteFilter,
        PurgeLogSourceFilter $sourceFilter,
        PurgeLogOutcomeFilter $outcomeFilter,
        PurgeLogDateRangeFilter $dateRangeFilter,
        int $limit,
        int $offset,
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(max(0, $offset));

        $this->applyFilterConstraints($queryBuilder, $siteFilter, $sourceFilter, $outcomeFilter, $dateRangeFilter);

        $rows = $queryBuilder
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->mapRows($rows);
    }

    public function countByFilters(
        PurgeLogSiteFilter $siteFilter,
        PurgeLogSourceFilter $sourceFilter,
        PurgeLogOutcomeFilter $outcomeFilter,
        PurgeLogDateRangeFilter $dateRangeFilter,
    ): int {
        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->count('*')
            ->from(self::TABLE);

        $this->applyFilterConstraints($queryBuilder, $siteFilter, $sourceFilter, $outcomeFilter, $dateRangeFilter);

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    /**
     * @return list<PurgeLogRow>
     */
    public function findForCsvExport(
        PurgeLogSiteFilter $siteFilter,
        PurgeLogSourceFilter $sourceFilter,
        PurgeLogOutcomeFilter $outcomeFilter,
        PurgeLogDateRangeFilter $dateRangeFilter,
        int $limit,
    ): array {
        return $this->findByFilters(
            $siteFilter,
            $sourceFilter,
            $outcomeFilter,
            $dateRangeFilter,
            max(0, $limit),
            0,
        );
    }

    /**
     * @return list<string>
     */
    public function findDistinctSiteIdentifiers(): array
    {
        $queryBuilder = $this->createQueryBuilder();
        $rows = $queryBuilder
            ->select('site_identifier')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('site_identifier')
            ->orderBy('site_identifier', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $siteIdentifiers = [];
        foreach ($rows as $row) {
            $siteIdentifier = trim((string)$row);
            if ($siteIdentifier === '') {
                $siteIdentifier = 'unknown';
            }
            $siteIdentifiers[$siteIdentifier] = true;
        }

        return array_keys($siteIdentifiers);
    }

    /**
     * @return list<array{siteIdentifier: string, count: int}>
     */
    public function findSiteBreakdown(): array
    {
        $queryBuilder = $this->createQueryBuilder();
        $rows = $queryBuilder
            ->select('site_identifier')
            ->addSelectLiteral('COUNT(*) AS count')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('site_identifier')
            ->orderBy('count', 'DESC')
            ->addOrderBy('site_identifier', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $breakdown = [];
        foreach ($rows as $row) {
            $siteIdentifier = trim((string)($row['site_identifier'] ?? $row['siteIdentifier'] ?? ''));
            if ($siteIdentifier === '') {
                $siteIdentifier = 'unknown';
            }
            $breakdown[$siteIdentifier] = ($breakdown[$siteIdentifier] ?? 0) + (int)($row['count'] ?? 0);
        }

        $result = [];
        foreach ($breakdown as $siteIdentifier => $count) {
            $result[] = [
                'siteIdentifier' => $siteIdentifier,
                'count' => $count,
            ];
        }

        usort(
            $result,
            static function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];
                if ($countComparison !== 0) {
                    return $countComparison;
                }

                return $left['siteIdentifier'] <=> $right['siteIdentifier'];
            },
        );

        return $result;
    }

    public function purgeOlderThan(DateTimeInterface $cutoff): int
    {
        $queryBuilder = $this->createQueryBuilder();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($cutoff->getTimestamp(), Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    public function purgeOlderThanBySiteIdentifier(string $siteIdentifier, DateTimeInterface $cutoff): int
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($cutoff->getTimestamp(), Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * @param list<string> $excludedSiteIdentifiers
     */
    public function purgeOlderThanForOtherSites(DateTimeInterface $cutoff, array $excludedSiteIdentifiers): int
    {
        $excludedSiteIdentifiers = array_values(array_unique(array_filter(
            array_map(static fn (string $siteIdentifier): string => trim($siteIdentifier), $excludedSiteIdentifiers),
            static fn (string $siteIdentifier): bool => $siteIdentifier !== '',
        )));

        if ($excludedSiteIdentifiers === []) {
            return $this->purgeOlderThan($cutoff);
        }

        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($cutoff->getTimestamp(), Connection::PARAM_INT)),
                $queryBuilder->expr()->notIn(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($excludedSiteIdentifiers, Connection::PARAM_STR_ARRAY),
                ),
            );

        return $queryBuilder->executeStatement();
    }

    /**
     * @param list<string> $requestIds
     */
    public function markFlushedByRequestIds(array $requestIds, int $latencyMs): int
    {
        return $this->markOutcomeByRequestIds($requestIds, PurgeLogOutcome::FLUSHED, $latencyMs);
    }

    /**
     * @param list<string> $requestIds
     */
    public function markTruncatedByRequestIds(array $requestIds, int $latencyMs): int
    {
        return $this->markOutcomeByRequestIds($requestIds, PurgeLogOutcome::TRUNCATED, $latencyMs);
    }

    /**
     * @param list<string> $requestIds
     */
    public function markSkippedByRequestIds(array $requestIds, int $latencyMs): int
    {
        return $this->markOutcomeByRequestIds($requestIds, PurgeLogOutcome::SKIPPED, $latencyMs);
    }

    /**
     * @param list<string> $requestIds
     */
    public function markFailedByRequestIds(array $requestIds, int $latencyMs): int
    {
        return $this->markOutcomeByRequestIds($requestIds, PurgeLogOutcome::FAILED, $latencyMs);
    }

    /**
     * @param list<string> $requestIds
     */
    private function markOutcomeByRequestIds(array $requestIds, PurgeLogOutcome $outcome, int $latencyMs): int
    {
        $requestIds = array_values(array_unique(array_filter(array_map('trim', $requestIds))));
        if ($requestIds === []) {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder();

        return $queryBuilder
            ->update(self::TABLE)
            ->set('outcome', $outcome->value)
            ->set('latency_ms', max(0, $latencyMs))
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('outcome', $queryBuilder->createNamedParameter(PurgeLogOutcome::ENQUEUED->value)),
                $queryBuilder->expr()->in(
                    'request_id',
                    $queryBuilder->createNamedParameter($requestIds, Connection::PARAM_STR_ARRAY),
                ),
            )
            ->executeStatement();
    }

    private function createQueryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    private function normalizeSiteIdentifier(string $siteIdentifier): string
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return 'unknown';
        }

        return $siteIdentifier;
    }

    /**
     * @param list<string> $values
     */
    private function encodeList(array $values): string
    {
        return (string)json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function applyFilterConstraints(
        QueryBuilder $queryBuilder,
        PurgeLogSiteFilter $siteFilter,
        PurgeLogSourceFilter $sourceFilter,
        PurgeLogOutcomeFilter $outcomeFilter,
        PurgeLogDateRangeFilter $dateRangeFilter,
    ): void {
        $constraints = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];

        $siteIdentifiers = $siteFilter->getSiteIdentifiers();
        if ($siteIdentifiers !== []) {
            $constraints[] = $this->buildSiteIdentifierConstraint($queryBuilder, $siteIdentifiers);
        }

        $sources = $sourceFilter->getSources();
        if ($sources !== []) {
            $constraints[] = $queryBuilder->expr()->in(
                'source',
                $queryBuilder->createNamedParameter(
                    array_map(static fn (PurgeSource $source): string => $source->value, $sources),
                    Connection::PARAM_STR_ARRAY,
                ),
            );
        }

        $outcomes = $outcomeFilter->getOutcomes();
        if ($outcomes !== []) {
            $constraints[] = $queryBuilder->expr()->in(
                'outcome',
                $queryBuilder->createNamedParameter(
                    array_map(static fn (PurgeLogOutcome $outcome): string => $outcome->value, $outcomes),
                    Connection::PARAM_STR_ARRAY,
                ),
            );
        }

        if ($dateRangeFilter->getFrom() instanceof DateTimeInterface) {
            $constraints[] = $queryBuilder->expr()->gte(
                'crdate',
                $queryBuilder->createNamedParameter($dateRangeFilter->getFrom()->getTimestamp(), Connection::PARAM_INT),
            );
        }

        if ($dateRangeFilter->getTo() instanceof DateTimeInterface) {
            $constraints[] = $queryBuilder->expr()->lte(
                'crdate',
                $queryBuilder->createNamedParameter($dateRangeFilter->getTo()->getTimestamp(), Connection::PARAM_INT),
            );
        }

        $queryBuilder->where(...$constraints);
    }

    /**
     * @param list<string> $siteIdentifiers
     */
    private function buildSiteIdentifierConstraint(QueryBuilder $queryBuilder, array $siteIdentifiers): string
    {
        $hasUnknownBucket = in_array('unknown', $siteIdentifiers, true);
        $explicitSiteIdentifiers = array_values(array_filter(
            $siteIdentifiers,
            static fn (string $siteIdentifier): bool => $siteIdentifier !== 'unknown',
        ));

        $constraints = [];
        if ($explicitSiteIdentifiers !== []) {
            $constraints[] = count($explicitSiteIdentifiers) === 1
                ? $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($explicitSiteIdentifiers[0]))
                : $queryBuilder->expr()->in('site_identifier', $queryBuilder->createNamedParameter($explicitSiteIdentifiers, Connection::PARAM_STR_ARRAY));
        }

        if ($hasUnknownBucket) {
            $constraints[] = $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter('unknown'));
            $constraints[] = $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter(''));
        }

        if (count($constraints) === 1) {
            return $constraints[0];
        }

        return (string)$queryBuilder->expr()->or(...$constraints);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<PurgeLogRow>
     */
    private function mapRows(array $rows): array
    {
        $purgeLogRows = [];
        foreach ($rows as $row) {
            $purgeLogRows[] = PurgeLogRow::fromDatabaseRow($row);
        }

        return $purgeLogRows;
    }
}
