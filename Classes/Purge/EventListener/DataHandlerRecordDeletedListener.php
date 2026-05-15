<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\LoopbackDispatcher;
use Skom\Lscache\Purge\PurgePayload;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class DataHandlerRecordDeletedListener
{
    public function __construct(
        private DataHandlerTagResolver $tagResolver,
        private PurgeServiceInterface $purgeService,
        private ?LoopbackDispatcher $loopbackDispatcher = null,
        private ?PurgeLogRepository $purgeLogRepository = null,
        private ?SiteFinder $siteFinder = null,
    ) {}

    /**
     * @param array<string, mixed> $recordToDelete
     */
    public function processCmdmap_deleteAction(string $table, int $uid, array $recordToDelete, bool $recordWasDeleted, DataHandler $dataHandler): void
    {
        if (!$recordWasDeleted) {
            return;
        }

        $pid = (int)($recordToDelete['pid'] ?? 0);
        $pageIds = $this->resolvePageIds($table, $uid, $pid);
        if ($pageIds === []) {
            return;
        }

        $tags = $this->tagResolver->resolveTagsForRecord($table, $uid, $pageIds);
        if ($tags === []) {
            return;
        }

        $triggerRef = sprintf('datahandler:delete:%s:%d', $table, $uid);
        $siteIdentifier = $this->tagResolver->resolveSingleSiteIdentifierForPageIds($pageIds);

        $this->purgeService->purgeTags($tags, PurgeSource::DATAHANDLER, $triggerRef, $siteIdentifier);
        $this->dispatchLoopback($tags, $triggerRef, $siteIdentifier);
    }

    /**
     * @return list<int>
     */
    private function resolvePageIds(string $table, int $uid, int $pid): array
    {
        // Purging page deletes must include the deleted page UID itself, not only its parent.
        if ($table === 'pages') {
            return array_values(array_unique(array_filter([$uid, $pid], static fn (int $pageId): bool => $pageId > 0)));
        }

        return $pid > 0 ? [$pid] : [];
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
