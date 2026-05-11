<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Http\TagSchema;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordDeletedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordMovedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerTagResolver;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
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
        $listener = $this->createSavedListener();
        $listener->processDatamap_afterDatabaseOperations('update', 'tt_content', 500, ['pid' => 1], $this->createDataHandlerMock());

        $payload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $payload->tags());
    }

    #[Test]
    public function saveEventResolvesPidFromDatabaseWhenPidIsNotInChangedFieldValues(): void
    {
        $listener = $this->createSavedListener();
        $listener->processDatamap_afterDatabaseOperations('update', 'tt_content', 500, ['header' => 'Updated'], $this->createDataHandlerMock());

        $payload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $payload->tags());
    }

    #[Test]
    public function deleteEventEnqueuesRecordAndPageTags(): void
    {
        $listener = $this->createDeletedListener();
        $listener->processCmdmap_deleteAction('tt_content', 500, ['pid' => 1], true, $this->createDataHandlerMock());

        $payload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $payload->tags());
    }

    #[Test]
    public function moveEventEnqueuesOldAndNewPageTags(): void
    {
        $listener = $this->createMovedListener();
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
        $listener = $this->createSavedListener();
        $listener->processDatamap_afterDatabaseOperations('update', 'be_users', 5, ['pid' => 1], $this->createDataHandlerMock());

        $payload = $this->getQueue()->drain();
        self::assertSame([], $payload->tags());
    }

    #[Test]
    public function hideAndUnhideUpdatesEnqueueSameScopedTagSetAsRegularSave(): void
    {
        $listener = $this->createSavedListener();
        $dataHandler = $this->createDataHandlerMock();

        $listener->processDatamap_afterDatabaseOperations('update', 'tt_content', 500, ['hidden' => 1], $dataHandler);
        $hiddenPayload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $hiddenPayload->tags());

        $listener->processDatamap_afterDatabaseOperations('update', 'tt_content', 500, ['hidden' => 0], $dataHandler);
        $unhiddenPayload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $unhiddenPayload->tags());
    }

    #[Test]
    public function repeatedSameRecordUpdatesAreDeduplicatedWithinTheRequestQueue(): void
    {
        $listener = $this->createSavedListener();

        for ($i = 0; $i < 500; $i++) {
            $listener->processDatamap_afterDatabaseOperations('update', 'tt_content', 500, ['header' => 'Batch ' . $i], $this->createDataHandlerMock());
        }

        $payload = $this->getQueue()->drain();
        self::assertSame(['main_site', 'main_page_1', 'main_record_tt_content_500'], $payload->tags());
    }

    #[Test]
    public function dataHandlerQueuedPurgeIsEmittedByMiddlewarePiggybackHeader(): void
    {
        $this->createSavedListener()->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            500,
            ['pid' => 1],
            $this->createDataHandlerMock(),
        );

        $response = $this->get(SkomLscacheMiddleware::class)->process(
            $this->createFrontendRequest(),
            new DataHandlerFunctionalFixedResponseHandler(new Response()),
        );

        self::assertSame(
            'tag=main_site,tag=main_page_1,tag=main_record_tt_content_500',
            $response->getHeaderLine('X-LiteSpeed-Purge'),
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

    private function createMovedListener(): DataHandlerRecordMovedListener
    {
        return new DataHandlerRecordMovedListener(
            $this->createTagResolver(),
            $this->get(PurgeServiceInterface::class),
            $this->get(ConnectionPool::class),
        );
    }

    private function createTagResolver(): DataHandlerTagResolver
    {
        return new DataHandlerTagResolver(
            $this->get(SiteFinder::class),
            $this->get(TagSchema::class),
        );
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
    defaultTtl: 300
    loopback:
      rateLimit: 120
    tagNamespace: 'main'
    autopurge:
      dataHandler: %s
websiteTitle: ''
YAML,
                $autoPurgeEnabled ? 'true' : 'false',
            ),
        );
        $this->get(SiteFinder::class)->siteConfigurationChanged();

    }

    private function createFrontendRequest(): ServerRequest
    {
        $site = new Site(
            'main',
            1,
            ['base' => 'https://example.org/', 'languages' => [[
                'languageId' => 0,
                'base' => '/',
                'title' => 'Default',
                'navigationTitle' => 'Default',
                'flag' => 'de',
                'locale' => 'de_DE.UTF-8',
            ]]],
            SiteSettings::createFromSettingsTree([
                'lscache' => [
                    'enabled' => true,
                    'defaultTtl' => 300,
                    'tagNamespace' => 'main',
                ],
            ]),
        );

        return (new ServerRequest(new Uri('https://example.org/'), 'GET', null, [], [
            'SERVER_SOFTWARE' => 'OpenLiteSpeed',
        ]))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('routing', new PageArguments(1, '0', []));
    }
}

final class DataHandlerFunctionalFixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
