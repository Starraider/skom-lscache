<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Backend;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Backend\Controller\PageStatusController;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
use Skom\Lscache\Http\TagSchema;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener;
use Skom\Lscache\Purge\EventListener\DataHandlerTagResolver;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\SiteFinder;

require_once __DIR__ . '/AbstractBackendModuleTestCase.php';

final class PageStatusControllerTest extends AbstractBackendModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRootPage(2, 'Secondary Root', '/secondary-root');
        $this->overrideSiteConfiguration('secondary', 'https://secondary.example.test/', 'Secondary Site', 2, true, 'secondary');
    }

    #[Test]
    public function rendersDryRunDetailsAndProbeLiveContractForValidPage(): void
    {
        $this->overrideSiteConfiguration('main', 'https://example.test/', 'Main Site', 1, true, 'shared-main');
        $this->seedPage(42, '/status-page');
        $this->insertAuditRow('main', 'main-old', time() - 120, PurgeLogOutcome::FLUSHED, PurgeSource::DATAHANDLER, ['shared-main_page_42'], [], 'pages:42');
        $this->insertAuditRow('main', 'main-new', time() - 60, PurgeLogOutcome::FLUSHED, PurgeSource::MODULE, ['shared-main_page_42', 'shared-main_site'], [], 'pages:42');
        $this->insertAuditRow('secondary', 'secondary-ignore', time() - 30, PurgeLogOutcome::FLUSHED, PurgeSource::API, ['secondary_page_42'], [], 'pages:42');
        $this->createBackendUser('de');

        /** @var PageStatusController $controller */
        $controller = $this->get(PageStatusController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => 'main',
                'pageUid' => 42,
            ],
            '/typo3/module/web/lscache/page-status',
            'web_SkomLscacheCache.page-status',
        ));
        $html = (string)$response->getBody();
        $xpath = $this->createXPath($html);

        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-page-status-root])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-page-status-cacheable="1"])'));
        self::assertSame('cacheable', trim((string)$xpath->evaluate('string(//*[@data-page-status-reason])')));
        self::assertSame('3600', trim((string)$xpath->evaluate('string(//*[@data-page-status-ttl])')));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-page-status-tag="shared-main_site"])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-page-status-tag="shared-main_page_42"])'));
        self::assertSame('shared-main_page_*', trim((string)$xpath->evaluate('string((//*[@data-page-status-tag-prefix="page"]//code)[1])')));
        self::assertSame('shared-main_record_*', trim((string)$xpath->evaluate('string((//*[@data-page-status-tag-prefix="record"]//code)[1])')));
        self::assertSame('shared-main_site', trim((string)$xpath->evaluate('string((//*[@data-page-status-tag-prefix="site"]//code)[1])')));
        self::assertSame('main-new', trim((string)$xpath->evaluate('string((//*[@data-page-status-recent-row])[1]/@data-page-status-recent-row)')));
        self::assertSame('https://example.test/status-page', trim((string)$xpath->evaluate('string(//*[@data-page-status-probe-target]/@data-page-status-probe-target)')));
        self::assertStringContainsString('Probe Live', $html);
    }

    #[Test]
    public function malformedPageUidRendersLocalizedErrorPanel(): void
    {
        $this->createBackendUser('de');

        /** @var PageStatusController $controller */
        $controller = $this->get(PageStatusController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => 'main',
                'pageUid' => 'abc',
            ],
            '/typo3/module/web/lscache/page-status',
            'web_SkomLscacheCache.page-status',
        ));
        $html = (string)$response->getBody();

        self::assertStringContainsString('data-page-status-error', $html);
        self::assertStringContainsString('Bitte gib eine gültige Seiten-UID größer als 0 ein.', $html);
    }

    #[Test]
    public function unknownPageRendersLocalizedErrorPanel(): void
    {
        $this->createBackendUser('de');

        /** @var PageStatusController $controller */
        $controller = $this->get(PageStatusController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => 'main',
                'pageUid' => 99999,
            ],
            '/typo3/module/web/lscache/page-status',
            'web_SkomLscacheCache.page-status',
        ));
        $html = (string)$response->getBody();

        self::assertStringContainsString('data-page-status-error', $html);
        self::assertStringContainsString('Die angegebene Seite wurde nicht gefunden oder ist nicht zugänglich.', $html);
    }

    #[Test]
    public function pageOutsideSelectedSiteRendersLocalizedErrorPanel(): void
    {
        $this->overrideSiteConfiguration('main', 'https://example.test/', 'Main Site', 1, true, 'main');
        $this->overrideSiteConfiguration('secondary', 'https://secondary.example.test/', 'Secondary Site', 2, true, 'secondary');
        $this->seedRootPage(2, 'Secondary Root', '/secondary-root');
        $this->seedPage(200, '/status-page-secondary', 2, 'Secondary Status Page');
        $this->createBackendUser('de');

        /** @var PageStatusController $controller */
        $controller = $this->get(PageStatusController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => 'main',
                'pageUid' => 200,
            ],
            '/typo3/module/web/lscache/page-status',
            'web_SkomLscacheCache.page-status',
        ));
        $html = (string)$response->getBody();

        self::assertStringContainsString('data-page-status-error', $html);
        self::assertStringContainsString('Die gewählte Seite gehört nicht zur aktuell ausgewählten Site.', $html);
    }

    #[Test]
    public function pageOutsideEnabledLscacheSiteRendersLocalizedErrorPanel(): void
    {
        $this->seedPage(15, '/disabled-site-page');
        $this->overrideSiteConfiguration('main', 'https://example.test/', 'Main Site', 1, false, 'main');
        $this->createBackendUser('de');

        /** @var PageStatusController $controller */
        $controller = $this->get(PageStatusController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => 'main',
                'pageUid' => 15,
            ],
            '/typo3/module/web/lscache/page-status',
            'web_SkomLscacheCache.page-status',
        ));
        $html = (string)$response->getBody();

        self::assertStringContainsString('data-page-status-error', $html);
        self::assertStringContainsString('Für die gewählte Seite ist LSCache nicht aktiviert.', $html);
    }

    #[Test]
    public function refreshedStatusShowsNewestMatchingPurgeFirstAfterContentChange(): void
    {
        $this->seedPage(55, '/refresh-status');
        $this->insertAuditRow('main', 'purge-old', time() - 120, PurgeLogOutcome::FLUSHED, PurgeSource::DATAHANDLER, ['main_page_55'], [], 'pages:55');
        $this->createBackendUser('de');

        /** @var PageStatusController $controller */
        $controller = $this->get(PageStatusController::class);
        $beforeRefresh = (string)$controller->handleRequest($this->createBackendRequest(
            [
                'site' => 'main',
                'pageUid' => 55,
            ],
            '/typo3/module/web/lscache/page-status',
            'web_SkomLscacheCache.page-status',
        ))->getBody();
        $beforeXpath = $this->createXPath($beforeRefresh);
        self::assertSame('purge-old', trim((string)$beforeXpath->evaluate('string((//*[@data-page-status-recent-row])[1]/@data-page-status-recent-row)')));

        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', [
            'header' => 'Changed content',
            'tstamp' => time(),
        ], [
            'uid' => 550,
        ]);
        $this->triggerAutoPurgeForContentUpdate(550, 55, 'main');

        $latestRow = $this->get(PurgeLogRepository::class)->findRecentBySite('main', 1, 0)[0];
        $afterRefresh = (string)$controller->handleRequest($this->createBackendRequest(
            [
                'site' => 'main',
                'pageUid' => 55,
            ],
            '/typo3/module/web/lscache/page-status',
            'web_SkomLscacheCache.page-status',
        ))->getBody();
        $afterXpath = $this->createXPath($afterRefresh);

        self::assertNotSame('purge-old', $latestRow->requestId);
        self::assertSame(PurgeLogOutcome::FLUSHED, $latestRow->outcome);
        self::assertSame(PurgeSource::DATAHANDLER, $latestRow->source);
        self::assertContains('main_page_55', $latestRow->tags);
        self::assertSame($latestRow->requestId, trim((string)$afterXpath->evaluate('string((//*[@data-page-status-recent-row])[1]/@data-page-status-recent-row)')));
    }

    private function seedRootPage(int $pageUid, string $title, string $slug): void
    {
        $now = time();
        $pagesConnection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $existing = $pagesConnection->createQueryBuilder()
            ->select('uid')
            ->from('pages')
            ->where('uid = :uid')
            ->setParameter('uid', $pageUid)
            ->executeQuery()
            ->fetchOne();
        if ($existing !== false) {
            return;
        }

        $pagesConnection->insert('pages', [
            'uid' => $pageUid,
            'pid' => 0,
            'title' => $title,
            'slug' => $slug,
            'doktype' => 1,
            'perms_everybody' => 15,
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'content_from_pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
        ]);
    }

    private function seedPage(int $pageUid, string $slug, int $pid = 1, ?string $title = null): void
    {
        $now = time();
        $pagesConnection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $pagesConnection->insert('pages', [
            'uid' => $pageUid,
            'pid' => $pid,
            'title' => $title ?? 'Status Page ' . $pageUid,
            'slug' => $slug,
            'doktype' => 1,
            'perms_everybody' => 15,
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'content_from_pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
        ]);

        $contentConnection = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $contentConnection->insert('tt_content', [
            'uid' => $pageUid * 10,
            'pid' => $pageUid,
            'CType' => 'textmedia',
            'colPos' => 0,
            'header' => 'Status Content',
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
        ]);
    }

    private function triggerAutoPurgeForContentUpdate(int $contentUid, int $pageUid, string $siteIdentifier): void
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

        $listener->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            $contentUid,
            ['header' => 'Changed content'],
            $dataHandler,
        );

        $this->get(SkomLscacheMiddleware::class)->process(
            $this->createFrontendRequest($pageUid, $siteIdentifier),
            new PageStatusFixedResponseHandler(new Response()),
        );
    }

    private function createFrontendRequest(int $pageUid, string $siteIdentifier): ServerRequest
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier($siteIdentifier);

        return (new ServerRequest(new Uri('https://example.test/refresh-status'), 'GET', null, [], [
            'SERVER_SOFTWARE' => 'Apache',
        ]))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('routing', new PageArguments($pageUid, '0', []));
    }

    private function overrideSiteConfiguration(
        string $identifier,
        string $base,
        string $websiteTitle,
        int $rootPageId,
        bool $enabled,
        string $tagNamespace,
    ): void {
        $sitePath = $this->instancePath . '/typo3conf/sites/' . $identifier;
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        $enabledFlag = $enabled ? 'true' : 'false';

        file_put_contents(
            $sitePath . '/config.yaml',
            <<<YAML
base: '{$base}'
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
rootPageId: {$rootPageId}
websiteTitle: '{$websiteTitle}'
settings:
  lscache:
    enabled: $enabledFlag
    defaultTtl: 3600
    tagNamespace: '{$tagNamespace}'
    autopurge:
      dataHandler: true
      tables: []
    mirror:
      cacheFlushEvent: false
    bypass:
      loggedInFE: true
      setCookie: true
      paths: []
      pluginSignatures: []
      ctypes: []
    vary:
      cookieAllowlist: []
    loopback:
      secret: ''
      rateLimit: 120
      allowedRemoteAddrs:
        - '127.0.0.1'
        - '::1'
    probe:
      allowedHosts: []
    audit:
      retentionDays: 7
YAML,
        );

        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }

    private function createXPath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}

final class PageStatusFixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
