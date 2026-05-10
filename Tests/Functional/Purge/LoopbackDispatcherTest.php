<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Skom\Lscache\Purge\LoopbackDispatcher;
use Skom\Lscache\Purge\PurgePayload;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LoopbackDispatcherTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/skom_lscache',
    ];

    private function createSite(): Site
    {
        return new Site(
            'test-site',
            1,
            ['base' => 'http://localhost/', 'languages' => []],
            SiteSettings::createFromSettingsTree([
                'lscache' => [
                    'enabled' => true,
                    'loopback' => [
                        'secret' => 'test-secret',
                    ],
                ]
            ]),
        );
    }

    public function testDispatchMakesHttpRequestWithSignature(): void
    {
        $site = $this->createSite();
        $payload = new PurgePayload(['tag1'], [], []);

        // Mock RequestFactory to intercept the request
        $requestFactoryMock = $this->createMock(RequestFactory::class);
        $requestFactoryMock->expects(self::once())
            ->method('request')
            ->willReturnCallback(function (string $url, string $method, array $options) use ($payload) {
                self::assertSame('http://localhost/_lscache/purge', $url);
                self::assertSame('POST', $method);
                self::assertArrayHasKey('X-Skom-Lscache-Signature', $options['headers']);
                self::assertSame('application/json', $options['headers']['Content-Type']);
                
                $body = json_decode($options['body'], true);
                self::assertSame('test-site', $body['site']);
                self::assertSame('req-123', $body['requestId']);
                self::assertSame($payload->toArray(), $body['payload']);

                $expectedSignature = hash_hmac('sha256', $options['body'], 'test-secret');
                self::assertSame('sha256=' . $expectedSignature, $options['headers']['X-Skom-Lscache-Signature']);
                
                return new Response('php://temp', 200);
            });

        $dispatcher = new LoopbackDispatcher($requestFactoryMock, $this->createMock(\Psr\Log\LoggerInterface::class));
        $dispatcher->dispatch($payload, $site, 'req-123');
    }

    public function testDispatchHandlesTimeoutsGracefully(): void
    {
        $site = $this->createSite();
        $payload = new PurgePayload(['tag1'], [], []);

        $requestFactoryMock = $this->createMock(RequestFactory::class);
        $requestFactoryMock->expects(self::once())
            ->method('request')
            ->willThrowException(new ConnectException('Timeout', new Request('POST', 'http://localhost/')));

        $dispatcher = new LoopbackDispatcher($requestFactoryMock, $this->createMock(\Psr\Log\LoggerInterface::class));
        
        // This should not throw an exception, it should be swallowed
        $dispatcher->dispatch($payload, $site, 'req-timeout');
    }
}
