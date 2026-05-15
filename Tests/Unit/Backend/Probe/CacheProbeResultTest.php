<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Backend\Probe;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Backend\Probe\CacheProbeResult;

final class CacheProbeResultTest extends TestCase
{
    #[Test]
    public function fromResponsePrioritizesFiveHundredErrorsAndClassifiesTheCommonStates(): void
    {
        self::assertSame(
            CacheProbeResult::STATE_HTTP_ERROR,
            CacheProbeResult::fromResponse(
                new Response(503, ['X-LiteSpeed-Cache' => 'HIT', 'Cache-Control' => 'private']),
                'https://example.test/error',
            )->getState(),
        );

        self::assertSame(
            CacheProbeResult::STATE_HIT,
            CacheProbeResult::fromResponse(
                new Response(200, ['X-LiteSpeed-Cache' => 'HIT']),
                'https://example.test/hit',
            )->getState(),
        );

        self::assertSame(
            CacheProbeResult::STATE_HIT,
            CacheProbeResult::fromResponse(
                new Response(200, ['X-LiteSpeed-Cache' => 'HIT', 'Cache-Control' => 'private']),
                'https://example.test/hit-private',
            )->getState(),
        );

        self::assertSame(
            CacheProbeResult::STATE_MISS,
            CacheProbeResult::fromResponse(
                new Response(200, ['X-LiteSpeed-Cache' => 'MISS']),
                'https://example.test/miss',
            )->getState(),
        );

        self::assertSame(
            CacheProbeResult::STATE_NOT_CACHEABLE,
            CacheProbeResult::fromResponse(
                new Response(200, ['X-LiteSpeed-Cache-Control' => 'no-cache']),
                'https://example.test/private',
            )->getState(),
        );

        self::assertSame(
            CacheProbeResult::STATE_NO_LSCACHE_HEADER,
            CacheProbeResult::fromResponse(
                new Response(200, ['Set-Cookie' => 'session=1']),
                'https://example.test/none',
            )->getState(),
        );

        self::assertSame(
            CacheProbeResult::STATE_NOT_CACHEABLE,
            CacheProbeResult::fromResponse(
                new Response(401),
                'https://example.test/forbidden',
            )->getState(),
        );
    }

    #[Test]
    public function fromThrowableReturnsTimeoutForTimeoutLikeErrors(): void
    {
        $result = CacheProbeResult::fromThrowable(
            'https://example.test/slow',
            new \RuntimeException('cURL error 28: Operation timed out', 28),
        );

        self::assertSame(CacheProbeResult::STATE_TIMEOUT, $result->getState());
        self::assertSame('https://example.test/slow', $result->getTargetUrl());
        self::assertNull($result->getStatusCode());
    }
}
