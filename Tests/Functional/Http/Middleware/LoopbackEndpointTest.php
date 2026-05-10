<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Http\Middleware\LoopbackEndpointMiddleware;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\Audit\PurgeAuditRequestContext;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LoopbackEndpointTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/skom_lscache',
    ];

    private function createLoopbackRequest(
        array $body,
        string $secret,
        string $remoteAddr = '127.0.0.1',
        string $siteIdentifier = 'test-site',
    ): ServerRequest
    {
        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $jsonBody, $secret);

        $request = new ServerRequest(
            '/_lscache/purge',
            'POST',
            fopen('php://temp', 'rw+'),
            [
                'X-Skom-Lscache-Signature' => 'sha256=' . $signature,
                'Content-Type' => 'application/json',
            ],
            [
                'REMOTE_ADDR' => $remoteAddr,
            ]
        );
        $request->getBody()->write($jsonBody);

        $site = new Site(
            $siteIdentifier,
            1,
            ['base' => 'https://example.org/', 'languages' => []],
            SiteSettings::createFromSettingsTree([
                'lscache' => [
                    'enabled' => true,
                    'loopback' => [
                        'secret' => 'test-secret',
                        'allowedRemoteAddrs' => ['127.0.0.1'],
                        'rateLimit' => 120,
                    ],
                ]
            ]),
        );

        return $request->withAttribute('site', $site);
    }

    public function testEndpointReturns200AndEnqueuesPayload(): void
    {
        $middleware = $this->get(LoopbackEndpointMiddleware::class);
        $queue = $this->get(PurgeQueue::class);
        $auditContext = $this->get(PurgeAuditRequestContext::class);

        $requestId = 'req-123';
        $body = [
            'site' => 'test-site',
            'requestId' => $requestId,
            'timestamp' => time(),
            'payload' => [
                'tags' => ['tag1'],
                'urls' => [],
                'fullSiteFlushes' => [],
            ],
        ];

        $request = $this->createLoopbackRequest($body, 'test-secret');
        $response = $middleware->process($request, new FunctionalDummyRequestHandler());

        self::assertSame(200, $response->getStatusCode());
        $responseData = json_decode((string)$response->getBody(), true);
        self::assertSame($requestId, $responseData['requestId']);
        self::assertSame('loopback-dispatched', $responseData['outcome']);

        self::assertSame(['tag1'], $queue->drain()->tags());
        self::assertSame($requestId, $auditContext->peekRequestIds()[0]);
    }

    public function testEndpointRejectsMissingOrInvalidSignature(): void
    {
        $middleware = $this->get(LoopbackEndpointMiddleware::class);
        $body = [
            'site' => 'test-site',
            'requestId' => 'req-1',
            'timestamp' => time(),
            'payload' => ['tags' => [], 'urls' => [], 'fullSiteFlushes' => []],
        ];

        $request = $this->createLoopbackRequest($body, 'wrong-secret');
        $response = $middleware->process($request, new FunctionalDummyRequestHandler());
        self::assertSame(401, $response->getStatusCode());
    }

    public function testEndpointRejectsStaleTimestamp(): void
    {
        $middleware = $this->get(LoopbackEndpointMiddleware::class);
        $body = [
            'site' => 'test-site',
            'requestId' => 'req-2',
            'timestamp' => time() - 400,
            'payload' => ['tags' => [], 'urls' => [], 'fullSiteFlushes' => []],
        ];

        $request = $this->createLoopbackRequest($body, 'test-secret');
        $response = $middleware->process($request, new FunctionalDummyRequestHandler());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testEndpointRejectsNonAllowlistedIp(): void
    {
        $middleware = $this->get(LoopbackEndpointMiddleware::class);
        $body = [
            'site' => 'test-site',
            'requestId' => 'req-3',
            'timestamp' => time(),
            'payload' => ['tags' => [], 'urls' => [], 'fullSiteFlushes' => []],
        ];

        $request = $this->createLoopbackRequest($body, 'test-secret', '192.168.1.1');
        $response = $middleware->process($request, new FunctionalDummyRequestHandler());
        self::assertSame(403, $response->getStatusCode());
    }

    public function testEndpointRejectsMismatchedSite(): void
    {
        $middleware = $this->get(LoopbackEndpointMiddleware::class);
        $body = [
            'site' => 'wrong-site',
            'requestId' => 'req-4',
            'timestamp' => time(),
            'payload' => ['tags' => [], 'urls' => [], 'fullSiteFlushes' => []],
        ];

        $request = $this->createLoopbackRequest($body, 'test-secret');
        $response = $middleware->process($request, new FunctionalDummyRequestHandler());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testRateLimitingEnforced(): void
    {
        $middleware = $this->get(LoopbackEndpointMiddleware::class);
        $siteIdentifier = 'rate-limit-test-site';
        $body = [
            'site' => $siteIdentifier,
            'requestId' => 'req-rl',
            'timestamp' => time(),
            'payload' => ['tags' => [], 'urls' => [], 'fullSiteFlushes' => []],
        ];

        $request = $this->createLoopbackRequest($body, 'test-secret', '127.0.0.1', $siteIdentifier);

        for ($i = 0; $i < 120; $i++) {
            $response = $middleware->process($request, new FunctionalDummyRequestHandler());
            self::assertSame(200, $response->getStatusCode());
        }

        $response = $middleware->process($request, new FunctionalDummyRequestHandler());
        self::assertSame(429, $response->getStatusCode());
    }
}

final class FunctionalDummyRequestHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response('php://temp', 404);
    }
}
