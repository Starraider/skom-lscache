<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\LoopbackDispatcher;
use Skom\Lscache\Purge\PurgePayload;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class DataHandlerRecordMovedListener
{
    public function __construct(
        private DataHandlerTagResolver $tagResolver,
        private PurgeServiceInterface $purgeService,
        private ConnectionPool $connectionPool,
        private ?LoopbackDispatcher $loopbackDispatcher = null,
        private ?PurgeLogRepository $purgeLogRepository = null,
        private ?SiteFinder $siteFinder = null,
    ) {}

    /**
     * @param int|string $id
     * @param mixed $value
     * @param array<string, mixed>|bool $pasteUpdate
     */
    public function processCmdmap_preProcess(string $command, string $table, $id, &$value, DataHandler $dataHandler, array|bool &$pasteUpdate = false): void
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

        $triggerRef = sprintf('datahandler:move:%s:%d', $table, $recordUid);
        $siteIdentifier = $this->tagResolver->resolveSingleSiteIdentifierForPageIds([$oldPid, $newPid]);

        $this->purgeService->purgeTags($tags, PurgeSource::DATAHANDLER, $triggerRef, $siteIdentifier);
        $this->dispatchLoopback($tags, $triggerRef, $siteIdentifier);
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

    /**
     * @param list<string> $tags
     */
    private function dispatchLoopback(array $tags, string $triggerRef, ?string $siteIdentifier): void
    {
        if ($siteIdentifier === null || $this->loopbackDispatcher === null || $this->purgeLogRepository === null || $this->siteFinder === null) {
            return;
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return;
        }

        $requestId = $this->purgeLogRepository->findNewestRequestIdByTrigger($siteIdentifier, PurgeSource::DATAHANDLER, $triggerRef);
        if ($requestId === null) {
            return;
        }

        $startedAt = microtime(true);
        $success = $this->loopbackDispatcher->dispatch(new PurgePayload($tags, [], []), $site, $requestId);
        if (!$success) {
            $this->purgeLogRepository->markFailedByRequestIds([$requestId], (int)round((microtime(true) - $startedAt) * 1000));
        }
    }
}
