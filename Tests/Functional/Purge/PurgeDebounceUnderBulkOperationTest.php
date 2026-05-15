<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Http\TagSchema;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerTagResolver;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[Group('slow')]
final class PurgeDebounceUnderBulkOperationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
        for ($uid = 1000; $uid < 1050; $uid++) {
            $this->insertContent($uid, 1);
        }

        $this->writeSiteConfiguration();
    }

    #[Test]
    public function fiftySavesCoalesceToDeduplicatedTagSet(): void
    {
        $listener = new DataHandlerRecordSavedListener(
            new DataHandlerTagResolver(
                $this->get(SiteFinder::class),
                $this->get(TagSchema::class),
            ),
            $this->get(PurgeServiceInterface::class),
            $this->get(ConnectionPool::class),
        );

        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->substNEWwithIDs = [];

        for ($uid = 1000; $uid < 1050; $uid++) {
            $listener->processDatamap_afterDatabaseOperations(
                'update',
                'tt_content',
                $uid,
                ['header' => 'Bulk update ' . $uid],
                $dataHandler,
            );
        }

        $payload = $this->getQueue()->drain();

        self::assertContains('main_site', $payload->tags());
        self::assertContains('main_page_1', $payload->tags());
        self::assertCount(52, $payload->tags());
    }

    private function getQueue(): PurgeQueue
    {
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');

        return $queue;
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
