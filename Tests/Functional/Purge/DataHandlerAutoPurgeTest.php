<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Http\TagSchema;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordDeletedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerTagResolver;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DataHandlerAutoPurgeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
        $this->insertPage(2, 1, 'Delete Me');
        $this->insertContent(500, 1);
        $this->writeSiteConfiguration();
    }

    #[Test]
    public function savingContentEnqueuesExpectedScopedTags(): void
    {
        $this->createSavedListener()->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            500,
            ['pid' => 1],
            $this->createDataHandlerMock(),
        );

        $payload = $this->getQueue()->drain();

        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $payload->tags());
    }

    #[Test]
    public function deletingPageEnqueuesPagePurgeTags(): void
    {
        $this->createDeletedListener()->processCmdmap_deleteAction(
            'pages',
            2,
            ['pid' => 1],
            true,
            $this->createDataHandlerMock(),
        );

        $payload = $this->getQueue()->drain();

        self::assertContains('main_site', $payload->tags());
        self::assertContains('main_page_2', $payload->tags());
        self::assertContains('main_record_pages_2', $payload->tags());
    }

    private function createSavedListener(): DataHandlerRecordSavedListener
    {
        return new DataHandlerRecordSavedListener(
            $this->createTagResolver(),
            $this->get(PurgeServiceInterface::class),
            $this->get(ConnectionPool::class),
        );
    }

    private function createDeletedListener(): DataHandlerRecordDeletedListener
    {
        return new DataHandlerRecordDeletedListener(
            $this->createTagResolver(),
            $this->get(PurgeServiceInterface::class),
        );
    }

    private function createTagResolver(): DataHandlerTagResolver
    {
        return new DataHandlerTagResolver(
            $this->get(SiteFinder::class),
            $this->get(TagSchema::class),
        );
    }

    private function getQueue(): PurgeQueue
    {
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');

        return $queue;
    }

    private function createDataHandlerMock(): DataHandler
    {
        $mock = $this->createStub(DataHandler::class);
        $mock->substNEWwithIDs = [];

        return $mock;
    }

    private function insertPage(int $uid, int $pid, string $title): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid' => $uid,
            'pid' => $pid,
            'title' => $title,
            'slug' => '/' . strtolower(str_replace(' ', '-', $title)),
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

    private function writeSiteConfiguration(): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/main';
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        file_put_contents(
            $sitePath . '/config.yaml',
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
    enabled: true
    defaultTtl: 300
    loopback:
      rateLimit: 120
    tagNamespace: 'main'
    autopurge:
      dataHandler: true
websiteTitle: ''
YAML,
        );

        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }
}
