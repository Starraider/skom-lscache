<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Http\HeaderWriter;
use TYPO3\CMS\Core\Http\Response;

final class HeaderWriterTest extends TestCase
{
    #[Test]
    public function writesCacheableHeadersWithExactGrammar(): void
    {
        $response = (new HeaderWriter())->writeCacheable(
            new Response(),
            3600,
            ['siteA_site', 'siteA_page_42'],
            ['fe_typo_user', 'my_cookie'],
        );

        self::assertSame('public,max-age=3600', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertSame('siteA_site,siteA_page_42', $response->getHeaderLine('X-LiteSpeed-Tag'));
        self::assertSame('cookie=fe_typo_user,cookie=my_cookie', $response->getHeaderLine('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function omitsVaryHeaderWhenCookieAllowlistIsEmpty(): void
    {
        $response = (new HeaderWriter())->writeCacheable(
            new Response(),
            600,
            ['siteA_site'],
            [],
        );

        self::assertSame('public,max-age=600', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertSame('siteA_site', $response->getHeaderLine('X-LiteSpeed-Tag'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function writesPrivateHeaderAndRemovesTagAndVary(): void
    {
        $response = (new Response())
            ->withHeader('X-LiteSpeed-Tag', 'stale')
            ->withHeader('X-LiteSpeed-Vary', 'cookie=stale');

        $response = (new HeaderWriter())->writePrivate($response);

        self::assertSame('no-cache, private', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Tag'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Vary'));
    }
}
