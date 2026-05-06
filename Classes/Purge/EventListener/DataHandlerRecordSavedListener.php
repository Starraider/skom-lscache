<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final readonly class DataHandlerRecordSavedListener
{
    public function __construct(
        private DataHandlerTagResolver $tagResolver,
        private PurgeServiceInterface $purgeService,
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
        $pid = (int)($fieldValues['pid'] ?? 0);
        if ($recordUid <= 0 || $pid <= 0) {
            return;
        }

        $tags = $this->tagResolver->resolveTagsForRecord($table, $recordUid, [$pid]);
        if ($tags === []) {
            return;
        }

        $this->purgeService->purgeTags($tags, PurgeSource::DATAHANDLER, sprintf('datahandler:save:%s:%d', $table, $recordUid));
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
}
