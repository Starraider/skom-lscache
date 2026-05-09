<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Backend;

use DateTimeImmutable;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Backend\Routing\Route as BackendRoute;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractBackendModuleTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
        $this->writeSiteConfiguration('main', 'https://example.test/', 'Main Site');
        $this->writeSiteConfiguration('secondary', 'https://secondary.example.test/', 'Secondary Site');
        $this->clearAuditLog();
    }

    protected function createBackendUser(string $language = 'de'): BackendUserAuthentication
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->delete('be_users', ['uid' => 1]);
        $connection->insert('be_users', [
            'uid' => 1,
            'pid' => 0,
            'username' => 'editor',
            'password' => 'password',
            'admin' => 1,
            'usergroup' => '',
            'userMods' => 'web_SkomLscacheCache',
            'lang' => $language,
            'workspace_id' => 0,
            'uc' => '',
            'tstamp' => time(),
            'crdate' => time(),
            'lastlogin' => 0,
            'disable' => 0,
            'starttime' => 0,
            'endtime' => 0,
            'deleted' => 0,
        ]);

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create($language);

        return $backendUser;
    }

    protected function createBackendRequest(
        array $queryParams = [],
        string $path = '/typo3/module/web/lscache',
        string $routeIdentifier = 'web_SkomLscacheCache',
        string $method = 'GET',
    ): ServerRequest
    {
        $queryString = http_build_query($queryParams);
        $requestUri = $path . ($queryString !== '' ? '?' . $queryString : '');
        $request = new ServerRequest(
            new Uri('https://typo3-testing.local' . $requestUri),
            $method,
            null,
            [],
            [
                'DOCUMENT_ROOT' => $this->instancePath,
                'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request',
                'HTTP_HOST' => 'typo3-testing.local',
                'SERVER_NAME' => 'typo3-testing.local',
                'SERVER_ADDR' => '127.0.0.1',
                'REMOTE_ADDR' => '127.0.0.1',
                'SCRIPT_NAME' => '/index.php',
                'PHP_SELF' => '/index.php',
                'SCRIPT_FILENAME' => $this->instancePath . '/index.php',
                'PATH_TRANSLATED' => $this->instancePath . '/index.php',
                'QUERY_STRING' => $queryString,
                'REQUEST_URI' => $requestUri,
                'REQUEST_METHOD' => $method,
                'HTTPS' => 'on',
                'SERVER_PORT' => '443',
            ],
        );

        return $request
            ->withQueryParams($queryParams)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request))
            ->withAttribute('route', new BackendRoute(str_replace('/typo3', '', $path), [
                '_identifier' => $routeIdentifier,
                'packageName' => 'skom/lscache',
            ]))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function clearAuditLog(): void
    {
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $repository->purgeOlderThan(new DateTimeImmutable('@32503680000'));
    }

    /**
     * @param list<string> $tags
     * @param list<string> $urls
     */
    protected function insertAuditRow(
        string $siteIdentifier,
        string $requestId,
        int $crdate,
        PurgeLogOutcome $outcome,
        PurgeSource $source,
        array $tags = [],
        array $urls = [],
        ?string $triggerRef = null,
    ): void {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_skomlscache_purge_log')->insert('tx_skomlscache_purge_log', [
            'pid' => 0,
            'crdate' => $crdate,
            'site_identifier' => $siteIdentifier,
            'source' => $source->value,
            'trigger_ref' => $triggerRef,
            'tags' => json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'urls' => json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tag_count' => count($tags),
            'outcome' => $outcome->value,
            'latency_ms' => 0,
            'request_id' => $requestId,
        ]);
    }

    private function writeSiteConfiguration(string $identifier, string $base, string $websiteTitle): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/' . $identifier;
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

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
rootPageId: 1
websiteTitle: '{$websiteTitle}'
YAML,
        );

        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }
}
