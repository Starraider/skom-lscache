<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Purge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\TagNamespaceResolverInterface;

final class PurgeQueueTest extends TestCase
{
    #[Test]
    public function drainReturnsEmptyPayloadWhenQueueWasNeverFilled(): void
    {
        $subject = $this->createQueue();

        $payload = $subject->drain();

        self::assertSame([], $payload->tags());
        self::assertSame([], $payload->urls());
        self::assertSame([], $payload->fullSiteFlushes());
    }

    #[Test]
    public function deduplicatesTagsAndKeepsFirstSeenOrder(): void
    {
        $subject = $this->createQueue();

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
        $subject = $this->createQueue();

        $subject->enqueueTags(['main_page_10']);
        $subject->enqueueUrl('https://example.org/a');
        $subject->enqueueUrl('https://example.org/a');
        $subject->enqueueUrl('https://example.org/b');

        $payload = $subject->drain();

        self::assertSame(['main_page_10'], $payload->tags());
        self::assertSame(['https://example.org/a', 'https://example.org/b'], $payload->urls());
    }

    #[Test]
    public function fullSiteFlushCollapsesTagsUsingTheResolvedTagNamespace(): void
    {
        $subject = $this->createQueue([
            'main-site' => 'edge-main',
        ]);

        $subject->enqueueTags([
            'edge-main_site',
            'edge-main_page_10',
            'edge-main_record_tt_content_100',
            'other_page_20',
        ]);
        $subject->enqueueFullSiteFlush('main-site');
        $subject->enqueueTags(['edge-main_page_11', 'other_record_pages_20']);

        $payload = $subject->drain();

        self::assertSame(['other_page_20', 'other_record_pages_20'], $payload->tags());
        self::assertSame(['main-site'], $payload->fullSiteFlushes());
    }

    #[Test]
    public function fullSiteFlushRemovesTheSiteLevelTagFromThePayload(): void
    {
        $subject = $this->createQueue();

        $subject->enqueueTags([
            'main_site',
            'main_page_10',
            'other_page_20',
        ]);
        $subject->enqueueFullSiteFlush('main');

        $payload = $subject->drain();

        self::assertSame(['other_page_20'], $payload->tags());
        self::assertSame(['main'], $payload->fullSiteFlushes());
    }

    #[Test]
    public function drainIsIdempotent(): void
    {
        $subject = $this->createQueue();
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

    /**
     * @param array<string, string> $tagNamespacesBySite
     */
    private function createQueue(array $tagNamespacesBySite = []): PurgeQueue
    {
        return new PurgeQueue(new class($tagNamespacesBySite) implements TagNamespaceResolverInterface
        {
            /**
             * @param array<string, string> $tagNamespacesBySite
             */
            public function __construct(
                private readonly array $tagNamespacesBySite,
            ) {}

            public function resolveForSite(string $siteIdentifier): string
            {
                return $this->tagNamespacesBySite[$siteIdentifier] ?? $siteIdentifier;
            }
        });
    }
}
