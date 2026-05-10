<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DataHandlerRecordMovedListener
{
    public function __construct(
        private DataHandlerTagResolver $tagResolver,
        private PurgeServiceInterface $purgeService,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param int|string $id
     * @param mixed $value
     * @param array<string, mixed> $pasteUpdate
     */
    public function processCmdmap_preProcess(string $command, string $table, $id, &$value, DataHandler $dataHandler, array &$pasteUpdate): void
    {
        if ($command !== 'move') {
            return;
        }

        $recordUid = (int)$id;
        if ($recordUid <= 0) {
            return;
        }

        $oldPid = $this->fetchPid($table, $recordUid);
        $newPid = $this->resolveTargetPid($table, $value);

        $tags = $this->tagResolver->resolveTagsForRecord($table, $recordUid, [$oldPid, $newPid]);
        if ($tags === []) {
            return;
        }

        $this->purgeService->purgeTags(
            $tags,
            PurgeSource::DATAHANDLER,
            sprintf('datahandler:move:%s:%d', $table, $recordUid),
            $this->tagResolver->resolveSingleSiteIdentifierForPageIds([$oldPid, $newPid]),
        );
    }

    private function fetchPid(string $table, int $uid): int
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $pid = $connection->createQueryBuilder()
            ->select('pid')
            ->from($table)
            ->where('uid = :uid')
            ->setParameter('uid', $uid)
            ->executeQuery()
            ->fetchOne();

        return (int)$pid;
    }

    private function resolveTargetPid(string $table, mixed $value): int
    {
        $destination = (int)$value;
        if ($destination > 0) {
            return $destination;
        }

        if ($destination < 0) {
            return $this->fetchPid($table, abs($destination));
        }

        return 0;
    }
}
