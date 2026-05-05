<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Purge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Purge\PurgeQueue;

final class PurgeQueueTest extends TestCase
{
    #[Test]
    public function drainReturnsEmptyPayloadWhenQueueWasNeverFilled(): void
    {
        $subject = new PurgeQueue();

        $payload = $subject->drain();

        self::assertSame([], $payload->tags());
        self::assertSame([], $payload->urls());
        self::assertSame([], $payload->fullSiteFlushes());
    }

    #[Test]
    public function deduplicatesTagsAndKeepsFirstSeenOrder(): void
    {
        $subject = new PurgeQueue();

        $subject->enqueueTags(['main_page_10', 'main_page_10', 'main_record_tt_content_100']);
        $subject->enqueueTags(['main_record_tt_content_100', 'main_page_11']);

        $payload = $subject->drain();

        self::assertSame(
            ['main_page_10', 'main_record_tt_content_100', 'main_page_11'],
            $payload->tags(),
        );
    }

    #[Test]
    public function tracksUrlsSeparatelyFromTags(): void
    {
        $subject = new PurgeQueue();

        $subject->enqueueTags(['main_page_10']);
        $subject->enqueueUrl('https://example.org/a');
        $subject->enqueueUrl('https://example.org/a');
        $subject->enqueueUrl('https://example.org/b');

        $payload = $subject->drain();

        self::assertSame(['main_page_10'], $payload->tags());
        self::assertSame(['https://example.org/a', 'https://example.org/b'], $payload->urls());
    }

    #[Test]
    public function fullSiteFlushCollapsesTagsForTheSameSite(): void
    {
        $subject = new PurgeQueue();

        $subject->enqueueTags([
            'main_page_10',
            'main_record_tt_content_100',
            'other_page_20',
        ]);
        $subject->enqueueFullSiteFlush('main');
        $subject->enqueueTags(['main_page_11', 'other_record_pages_20']);

        $payload = $subject->drain();

        self::assertSame(['other_page_20', 'other_record_pages_20'], $payload->tags());
        self::assertSame(['main'], $payload->fullSiteFlushes());
    }

    #[Test]
    public function drainIsIdempotent(): void
    {
        $subject = new PurgeQueue();
        $subject->enqueueTags(['main_page_10']);
        $subject->enqueueUrl('https://example.org/a');
        $subject->enqueueFullSiteFlush('main');

        $firstDrain = $subject->drain();
        $secondDrain = $subject->drain();

        self::assertNotSame([], $firstDrain->fullSiteFlushes());
        self::assertSame([], $secondDrain->tags());
        self::assertSame([], $secondDrain->urls());
        self::assertSame([], $secondDrain->fullSiteFlushes());
    }
}
