<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Formats and writes all `X-LiteSpeed-*` response headers used by the extension.
 */
final readonly class HeaderWriter
{
    private const CACHE_CONTROL = 'X-LiteSpeed-Cache-Control';
    private const TAG = 'X-LiteSpeed-Tag';
    private const VARY = 'X-LiteSpeed-Vary';

    /**
     * Writes public LSCache cache-control, tag, and optional vary headers.
     *
     * @param ResponseInterface $response Response to modify immutably.
     * @param int $ttl Positive cache lifetime in seconds.
     * @param list<string> $tags Header-safe LSCache tags to join without spaces.
     * @param list<string> $varyCookies Cookie names to emit as repeated `cookie=` vary entries.
     */
    public function writeCacheable(
        ResponseInterface $response,
        int $ttl,
        array $tags,
        array $varyCookies,
    ): ResponseInterface {
        $response = $response
            ->withHeader(self::CACHE_CONTROL, 'public,max-age=' . $ttl)
            ->withHeader(self::TAG, implode(',', $tags));

        if ($varyCookies === []) {
            return $response->withoutHeader(self::VARY);
        }

        return $response->withHeader(
            self::VARY,
            implode(',', array_map(static fn(string $cookie): string => 'cookie=' . $cookie, $varyCookies)),
        );
    }

    /**
     * Writes the private no-cache LSCache header and removes tag/vary headers.
     *
     * @param ResponseInterface $response Response to modify immutably.
     */
    public function writePrivate(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader(self::CACHE_CONTROL, 'no-cache, private')
            ->withoutHeader(self::TAG)
            ->withoutHeader(self::VARY);
    }
}
