<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Purge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Purge\PurgeHeaderSerializer;
use Skom\Lscache\Purge\PurgePayload;

final class PurgeHeaderSerializerTest extends TestCase
{
    #[Test]
    public function serializesMixedPayloadForLitespeedPurgeHeader(): void
    {
        $subject = new PurgeHeaderSerializer();

        $result = $subject->serialize(
            new PurgePayload(
                tags: ['main_page_10'],
                urls: ['https://example.org/a'],
                fullSiteFlushes: ['main', '*'],
            ),
            static fn (string $siteIdentifier): string => $siteIdentifier,
        );

        self::assertSame(
            ['tag=main_page_10,tag=main_*,*,url=https://example.org/a'],
            $result['headers'],
        );
        self::assertSame(1, $result['tagCount']);
        self::assertSame('flushed', $result['outcome']);
    }

    #[Test]
    public function enforcesTagCapAndReportsTruncatedOutcome(): void
    {
        $subject = new PurgeHeaderSerializer();
        $tags = [];
        for ($i = 0; $i < 80; $i++) {
            $tags[] = 'main_page_' . $i;
        }

        $result = $subject->serialize(
            new PurgePayload(tags: $tags),
            static fn (string $siteIdentifier): string => $siteIdentifier,
        );

        self::assertSame('truncated', $result['outcome']);
        self::assertSame(64, $result['tagCount']);
        self::assertStringNotContainsString('main_page_79', implode(',', $result['headers']));
    }

    #[Test]
    public function splitsHeaderValuesToAvoidOversizedSingleHeaderLine(): void
    {
        $subject = new PurgeHeaderSerializer();
        $urls = [];
        for ($i = 0; $i < 30; $i++) {
            $urls[] = 'https://example.org/' . str_repeat((string)$i, 180);
        }

        $result = $subject->serialize(
            new PurgePayload(urls: $urls),
            static fn (string $siteIdentifier): string => $siteIdentifier,
        );

        self::assertGreaterThan(1, count($result['headers']));
        foreach ($result['headers'] as $headerValue) {
            self::assertLessThanOrEqual(3500, strlen($headerValue));
        }
    }

    #[Test]
    public function dropsSingleOversizedTokenInsteadOfEmittingOversizedHeaderLine(): void
    {
        $subject = new PurgeHeaderSerializer();
        $oversizedUrl = 'https://example.org/' . str_repeat('a', 3600);

        $result = $subject->serialize(
            new PurgePayload(urls: [$oversizedUrl]),
            static fn (string $siteIdentifier): string => $siteIdentifier,
        );

        self::assertSame([], $result['headers']);
        self::assertSame('truncated', $result['outcome']);
    }
}
