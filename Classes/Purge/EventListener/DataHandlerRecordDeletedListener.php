<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final readonly class DataHandlerRecordDeletedListener
{
    public function __construct(
        private DataHandlerTagResolver $tagResolver,
        private PurgeServiceInterface $purgeService,
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
        if ($pid <= 0) {
            return;
        }

        $tags = $this->tagResolver->resolveTagsForRecord($table, $uid, [$pid]);
        if ($tags === []) {
            return;
        }

        $this->purgeService->purgeTags($tags, PurgeSource::DATAHANDLER, sprintf('datahandler:delete:%s:%d', $table, $uid));
    }
}
