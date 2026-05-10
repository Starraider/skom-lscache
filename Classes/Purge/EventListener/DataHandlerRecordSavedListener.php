<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DataHandlerRecordSavedListener
{
    public function __construct(
        private DataHandlerTagResolver $tagResolver,
        private PurgeServiceInterface $purgeService,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param int|string $id
     * @param array<string, mixed> $fieldValues
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fieldValues, DataHandler $dataHandler): void
    {
        if ($status !== 'new' && $status !== 'update') {
            return;
        }

        $recordUid = $this->resolveRecordUid($id, $dataHandler);
        $pid = $this->resolvePid($table, $recordUid, $fieldValues);
        if ($recordUid <= 0 || $pid <= 0) {
            return;
        }

        $tags = $this->tagResolver->resolveTagsForRecord($table, $recordUid, [$pid]);
        if ($tags === []) {
            return;
        }

        $this->purgeService->purgeTags(
            $tags,
            PurgeSource::DATAHANDLER,
            sprintf('datahandler:save:%s:%d', $table, $recordUid),
            $this->tagResolver->resolveSingleSiteIdentifierForPageIds([$pid]),
        );
    }

    /** @param int|string $id */
    private function resolveRecordUid(int|string $id, DataHandler $dataHandler): int
    {
        if (is_int($id) || ctype_digit((string)$id)) {
            return (int)$id;
        }

        $resolved = $dataHandler->substNEWwithIDs[$id] ?? 0;
        return (int)$resolved;
    }

    /**
     * @param array<string, mixed> $fieldValues
     */
    private function resolvePid(string $table, int $recordUid, array $fieldValues): int
    {
        $pid = (int)($fieldValues['pid'] ?? 0);
        if ($pid > 0 || $recordUid <= 0) {
            return $pid;
        }

        $connection = $this->connectionPool->getConnectionForTable($table);
        $resolvedPid = $connection->createQueryBuilder()
            ->select('pid')
            ->from($table)
            ->where('uid = :uid')
            ->setParameter('uid', $recordUid)
            ->executeQuery()
            ->fetchOne();

        return (int)$resolvedPid;
    }
}
