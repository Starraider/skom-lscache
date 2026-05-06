<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordDeletedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordMovedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener;
use Skom\Lscache\Purge\PurgeQueue;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DataHandlerListenersFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
        $this->insertPage(2, 1, 'Secondary');
        $this->insertContent(500, 1);
        $this->insertContent(501, 1);
        $this->writeSiteConfiguration(true);
    }

    #[Test]
    public function saveEventEnqueuesRecordAndPageTags(): void
    {
        $listener = $this->get(DataHandlerRecordSavedListener::class);
        $listener->processDatamap_afterDatabaseOperations('update', 'tt_content', 500, ['pid' => 1], $this->createDataHandlerMock());

        $payload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $payload->tags());
    }

    #[Test]
    public function deleteEventEnqueuesRecordAndPageTags(): void
    {
        $listener = $this->get(DataHandlerRecordDeletedListener::class);
        $listener->processCmdmap_deleteAction('tt_content', 500, ['pid' => 1], true, $this->createDataHandlerMock());

        $payload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $payload->tags());
    }

    #[Test]
    public function moveEventEnqueuesOldAndNewPageTags(): void
    {
        $listener = $this->get(DataHandlerRecordMovedListener::class);
        $targetPid = 2;
        $pasteUpdate = [];
        $listener->processCmdmap_preProcess('move', 'tt_content', 501, $targetPid, $this->createDataHandlerMock(), $pasteUpdate);

        $payload = $this->getQueue()->drain();
        self::assertSame(
            ['main_site', 'main_page_1', 'main_record_tt_content_501', 'main_page_2'],
            $payload->tags(),
        );
    }

    #[Test]
    public function backendOnlyTableDoesNotEnqueuePurge(): void
    {
        $listener = $this->get(DataHandlerRecordSavedListener::class);
        $listener->processDatamap_afterDatabaseOperations('update', 'be_users', 5, ['pid' => 1], $this->createDataHandlerMock());

        $payload = $this->getQueue()->drain();
        self::assertSame([], $payload->tags());
    }

    private function getQueue(): PurgeQueue
    {
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');

        return $queue;
    }

    private function createDataHandlerMock(): DataHandler
    {
        $mock = $this->createMock(DataHandler::class);
        $mock->substNEWwithIDs = [];

        return $mock;
    }

    private function insertPage(int $uid, int $pid, string $title): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid' => $uid,
            'pid' => $pid,
            'title' => $title,
            'slug' => '/' . strtolower($title),
            'doktype' => 1,
            'perms_everybody' => 15,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'deleted' => 0,
        ]);
    }

    private function insertContent(int $uid, int $pid): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => $uid,
            'pid' => $pid,
            'CType' => 'text',
            'header' => 'Record ' . $uid,
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }

    private function writeSiteConfiguration(bool $autoPurgeEnabled): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/main';
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        file_put_contents(
            $sitePath . '/config.yaml',
            sprintf(
                <<<'YAML'
base: 'https://example.test/'
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
settings:
  lscache:
    tagNamespace: 'main'
    autopurge:
      dataHandler: %s
websiteTitle: ''
YAML,
                $autoPurgeEnabled ? 'true' : 'false',
            ),
        );
    }
}
