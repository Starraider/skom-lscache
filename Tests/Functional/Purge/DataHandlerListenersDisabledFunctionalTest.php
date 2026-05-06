<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener;
use Skom\Lscache\Purge\PurgeQueue;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DataHandlerListenersDisabledFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
        $this->insertContent(500, 1);
        $this->writeSiteConfiguration();
    }

    #[Test]
    public function saveEventDoesNotEnqueueWhenAutopurgeIsDisabled(): void
    {
        $listener = $this->get(DataHandlerRecordSavedListener::class);
        $listener->processDatamap_afterDatabaseOperations('update', 'tt_content', 500, ['pid' => 1], $this->createDataHandlerMock());

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
    tagNamespace: 'main'
    autopurge:
      dataHandler: false
websiteTitle: ''
YAML,
        );
    }
}
