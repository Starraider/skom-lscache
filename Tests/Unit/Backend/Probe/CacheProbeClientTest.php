<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Backend\Probe;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Skom\Lscache\Backend\Probe\CacheProbeClient;
use Skom\Lscache\Backend\Probe\CacheProbeResult;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;

final class CacheProbeClientTest extends TestCase
{
    #[Test]
    public function probeIssuesAHeadRequestWithTheExpectedHeadersAndTimeouts(): void
    {
        $originalHandler = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] ?? null;
        $captured = [];

        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(
            static function (RequestInterface $request, array $options) use (&$captured) {
                $captured = [
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri(),
                    'userAgent' => $request->getHeaderLine('User-Agent'),
                    'options' => $options,
                ];

                return Create::promiseFor(new Response(200, ['X-LiteSpeed-Cache' => 'HIT']));
            },
        );

        try {
            $result = (new CacheProbeClient(new RequestFactory(new GuzzleClientFactory())))->probe('https://example.test/probe');
        } finally {
            if ($originalHandler !== null) {
                $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $originalHandler;
            } else {
                unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
            }
        }

        self::assertSame('HEAD', $captured['method']);
        self::assertSame('https://example.test/probe', $captured['uri']);
        self::assertSame('skom/lscache-probe', $captured['userAgent']);
        self::assertEquals(3, $captured['options']['timeout']);
        self::assertEquals(3, $captured['options']['connect_timeout']);
        self::assertFalse($captured['options']['allow_redirects']);
        self::assertFalse($captured['options']['http_errors']);
        self::assertSame(CacheProbeResult::STATE_HIT, $result->getState());
    }
}
