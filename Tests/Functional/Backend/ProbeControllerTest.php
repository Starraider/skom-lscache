<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Backend;

use Closure;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Skom\Lscache\Backend\Controller\ProbeController;
use Skom\Lscache\Backend\Probe\CacheProbeClientInterface;
use Skom\Lscache\Backend\Probe\CacheProbeResult;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

require_once __DIR__ . '/AbstractBackendModuleTestCase.php';

final class ProbeControllerTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function resolvesRelativeTargetsAgainstTheSelectedSiteAndReturnsHitPayload(): void
    {
        $this->createBackendUser('de');
        $client = new CallbackProbeClient(
            static function (string $targetUrl): CacheProbeResult {
                return CacheProbeResult::fromResponse(
                    new Response(200, ['X-LiteSpeed-Cache' => 'HIT']),
                    $targetUrl,
                );
            },
        );

        $payload = $this->decodeJson($this->createController($client)->handleRequest(
            $this->createBackendRequest()
                ->withParsedBody([
                    'site' => 'main',
                    'target' => '/article',
                ]),
        ));

        self::assertSame(['https://example.test/article'], $client->targets);
        self::assertSame('hit', $payload['state']);
        self::assertSame('HIT', $payload['title']);
        self::assertSame('Die Antwort wurde aus dem Cache ausgeliefert.', $payload['message']);
        self::assertSame('https://example.test/article', $payload['targetUrl']);
    }

    #[Test]
    public function returnsLocalizedNotCacheablePayloadForAuthenticatedOnlyTargets(): void
    {
        $this->createBackendUser('de');
        $client = new CallbackProbeClient(
            static function (string $targetUrl): CacheProbeResult {
                return CacheProbeResult::fromResponse(
                    new Response(200, ['Set-Cookie' => 'session=1']),
                    $targetUrl,
                );
            },
        );

        $payload = $this->decodeJson($this->createController($client)->handleRequest(
            $this->createBackendRequest()
                ->withParsedBody([
                    'site' => 'main',
                    'target' => '/login',
                ]),
        ));

        self::assertSame(['https://example.test/login'], $client->targets);
        self::assertSame('not-cacheable', $payload['state']);
        self::assertSame('NOT-CACHEABLE', $payload['title']);
        self::assertSame('Die Antwort kann nicht gecacht werden.', $payload['message']);
        self::assertSame('https://example.test/login', $payload['targetUrl']);
    }

    #[Test]
    public function returnsLocalizedTimeoutPayloadForSlowTargets(): void
    {
        $this->createBackendUser('de');
        $client = new CallbackProbeClient(
            static function (string $targetUrl): CacheProbeResult {
                return CacheProbeResult::fromThrowable(
                    $targetUrl,
                    new \RuntimeException('cURL error 28: Operation timed out', 28),
                );
            },
        );

        $payload = $this->decodeJson($this->createController($client)->handleRequest(
            $this->createBackendRequest()
                ->withParsedBody([
                    'site' => 'main',
                    'target' => '/slow',
                ]),
        ));

        self::assertSame(['https://example.test/slow'], $client->targets);
        self::assertSame('timeout', $payload['state']);
        self::assertSame('TIMEOUT', $payload['title']);
        self::assertSame('Die Probe-Anfrage ist in ein Timeout gelaufen.', $payload['message']);
        self::assertSame('Die Anfrage hat das 3-Sekunden-Timeout überschritten.', $payload['details']);
        self::assertSame('https://example.test/slow', $payload['targetUrl']);
    }

    #[Test]
    public function returnsLocalizedHttpErrorPayloadForFiveHundredResponses(): void
    {
        $this->createBackendUser('de');
        $client = new CallbackProbeClient(
            static function (string $targetUrl): CacheProbeResult {
                return CacheProbeResult::fromResponse(
                    new Response(503, ['X-LiteSpeed-Cache' => 'MISS']),
                    $targetUrl,
                );
            },
        );

        $payload = $this->decodeJson($this->createController($client)->handleRequest(
            $this->createBackendRequest()
                ->withParsedBody([
                    'site' => 'main',
                    'target' => '/unavailable',
                ]),
        ));

        self::assertSame(['https://example.test/unavailable'], $client->targets);
        self::assertSame('http-error', $payload['state']);
        self::assertSame('HTTP-ERROR', $payload['title']);
        self::assertSame('Die Probe-Anfrage hat einen HTTP-Fehler zurückgegeben.', $payload['message']);
        self::assertSame('HTTP-Status 503.', $payload['details']);
        self::assertSame('https://example.test/unavailable', $payload['targetUrl']);
    }

    #[Test]
    public function blocksAbsoluteTargetsOutsideSelectedSiteWhenNoAllowlistIsConfigured(): void
    {
        $this->createBackendUser('de');
        $client = new CallbackProbeClient(
            static function (string $targetUrl): CacheProbeResult {
                return CacheProbeResult::fromResponse(
                    new Response(200, ['X-LiteSpeed-Cache' => 'HIT']),
                    $targetUrl,
                );
            },
        );

        $payload = $this->decodeJson($this->createController($client)->handleRequest(
            $this->createBackendRequest()
                ->withParsedBody([
                    'site' => 'main',
                    'target' => 'https://external.example/probe',
                ]),
        ));

        self::assertSame([], $client->targets);
        self::assertSame('not-cacheable', $payload['state']);
        self::assertSame('NOT-CACHEABLE', $payload['title']);
        self::assertSame('Der Ziel-Host ist für die Probe nicht erlaubt.', $payload['message']);
        self::assertSame('Verwende den Host der gewählten Site oder konfiguriere lscache.probe.allowedHosts in den Site-Einstellungen.', $payload['details']);
        self::assertSame('https://external.example/probe', $payload['targetUrl']);
    }

    #[Test]
    public function allowsAbsoluteTargetsConfiguredViaAllowedProbeHosts(): void
    {
        $this->createBackendUser('de');
        $this->overrideMainSiteConfigWithAllowedProbeHosts(['external.example']);
        $client = new CallbackProbeClient(
            static function (string $targetUrl): CacheProbeResult {
                return CacheProbeResult::fromResponse(
                    new Response(200, ['X-LiteSpeed-Cache' => 'HIT']),
                    $targetUrl,
                );
            },
        );

        $payload = $this->decodeJson($this->createController($client)->handleRequest(
            $this->createBackendRequest()
                ->withParsedBody([
                    'site' => 'main',
                    'target' => 'https://external.example/probe',
                ]),
        ));

        self::assertSame(['https://external.example/probe'], $client->targets);
        self::assertSame('hit', $payload['state']);
        self::assertSame('https://external.example/probe', $payload['targetUrl']);
    }

    #[Test]
    public function safelyHandlesNonArrayParsedBodies(): void
    {
        $this->createBackendUser('de');
        $client = new CallbackProbeClient(
            static function (string $targetUrl): CacheProbeResult {
                return CacheProbeResult::fromResponse(
                    new Response(200, ['X-LiteSpeed-Cache' => 'HIT']),
                    $targetUrl,
                );
            },
        );

        $payload = $this->decodeJson($this->createController($client)->handleRequest(
            $this->createBackendRequest([
                'site' => 'main',
                'target' => '/query-target',
            ])->withParsedBody((object)['target' => '/ignored']),
        ));

        self::assertSame(['https://example.test/query-target'], $client->targets);
        self::assertSame('hit', $payload['state']);
        self::assertSame('https://example.test/query-target', $payload['targetUrl']);
    }

    private function createController(CacheProbeClientInterface $client): ProbeController
    {
        /** @var SiteFinder $siteFinder */
        $siteFinder = $this->get(SiteFinder::class);
        /** @var LanguageServiceFactory $languageServiceFactory */
        $languageServiceFactory = $this->get(LanguageServiceFactory::class);

        return new ProbeController($client, $siteFinder, $languageServiceFactory);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $hosts
     */
    private function overrideMainSiteConfigWithAllowedProbeHosts(array $hosts): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/main';
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        $hostLines = '';
        foreach ($hosts as $host) {
            $hostLines .= "        - '" . $host . "'\n";
        }

        file_put_contents(
            $sitePath . '/config.yaml',
            <<<YAML
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
websiteTitle: 'Main Site'
settings:
  lscache:
    enabled: true
    defaultTtl: 3600
    tagNamespace: 'main'
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
      allowedHosts:
$hostLines
    audit:
      retentionDays: 7
YAML,
        );

        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }
}

final class CallbackProbeClient implements CacheProbeClientInterface
{
    /**
     * @var list<string>
     */
    public array $targets = [];

    /**
     * @param Closure(string): CacheProbeResult $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function probe(string $targetUrl): CacheProbeResult
    {
        $this->targets[] = $targetUrl;

        return ($this->callback)($targetUrl);
    }
}
