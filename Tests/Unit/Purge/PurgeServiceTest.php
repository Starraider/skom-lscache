<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Purge;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\Event\PurgeIssuedEvent;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\PurgeService;
use Skom\Lscache\Purge\TagNamespaceResolverInterface;

final class PurgeServiceTest extends TestCase
{
    #[Test]
    public function purgeTagsEnqueuesDispatchesEventAndWritesStructuredLog(): void
    {
        $queue = $this->createQueue();
        $dispatcher = new CollectingDispatcher();
        $logger = new InMemoryLogger();
        $subject = new PurgeService($queue, $dispatcher, $logger);
        $tags = ['main_page_10', 'main_record_tt_content_11'];

        $subject->purgeTags($tags, PurgeSource::API, 'manual-trigger');

        $payload = $queue->drain();
        self::assertSame($tags, $payload->tags());
        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(PurgeIssuedEvent::class, $dispatcher->events[0]);
        self::assertSame($tags, $dispatcher->events[0]->getTags());
        self::assertSame([], $dispatcher->events[0]->getUrls());
        self::assertSame(PurgeSource::API, $dispatcher->events[0]->getSource());
        self::assertSame('manual-trigger', $dispatcher->events[0]->getTriggerRef());
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $dispatcher->events[0]->getRequestId());
        self::assertGreaterThanOrEqual(time() - 1, $dispatcher->events[0]->getTimestamp());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('purge-issued', $logger->records[0]['message']);
        self::assertSame(
            ['site', 'source', 'tagCount', 'outcome', 'latencyMs', 'requestId'],
            array_keys($logger->records[0]['context']),
        );
        self::assertSame('api', $logger->records[0]['context']['source']);
        self::assertSame(2, $logger->records[0]['context']['tagCount']);
        self::assertSame('enqueued', $logger->records[0]['context']['outcome']);
    }

    #[Test]
    public function purgeTagsKeepsMoreThanSixtyFourTagsIntactAtApiLayer(): void
    {
        $queue = $this->createQueue();
        $subject = new PurgeService($queue, new CollectingDispatcher(), new InMemoryLogger());

        $tags = [];
        for ($i = 1; $i <= 70; $i++) {
            $tags[] = 'main_record_tt_content_' . $i;
        }

        $subject->purgeTags($tags, PurgeSource::CLI);

        self::assertCount(70, $queue->drain()->tags());
    }

    #[Test]
    public function purgeUrlEnqueuesUrlAndDispatchesOneEvent(): void
    {
        $queue = $this->createQueue();
        $dispatcher = new CollectingDispatcher();
        $logger = new InMemoryLogger();
        $subject = new PurgeService($queue, $dispatcher, $logger);

        $subject->purgeUrl('https://example.org/path', PurgeSource::MODULE);

        $payload = $queue->drain();
        self::assertSame(['https://example.org/path'], $payload->urls());
        self::assertCount(1, $dispatcher->events);
        self::assertSame([], $dispatcher->events[0]->getTags());
        self::assertSame(['https://example.org/path'], $dispatcher->events[0]->getUrls());
        self::assertCount(1, $logger->records);
        self::assertSame(0, $logger->records[0]['context']['tagCount']);
    }

    #[Test]
    public function purgeFullSiteEnqueuesFlushAndPropagatesSiteToEventAndLog(): void
    {
        $queue = $this->createQueue();
        $dispatcher = new CollectingDispatcher();
        $logger = new InMemoryLogger();
        $subject = new PurgeService($queue, $dispatcher, $logger);

        $subject->purgeFullSite('main', PurgeSource::DATAHANDLER, 'dh:pages:1');

        $payload = $queue->drain();
        self::assertSame(['main'], $payload->fullSiteFlushes());
        self::assertSame('main', $dispatcher->events[0]->getSite());
        self::assertSame('main', $logger->records[0]['context']['site']);
    }

    #[Test]
    public function purgeEverythingEnqueuesWildcardSiteFlush(): void
    {
        $queue = $this->createQueue();
        $dispatcher = new CollectingDispatcher();
        $logger = new InMemoryLogger();
        $subject = new PurgeService($queue, $dispatcher, $logger);

        $queue->enqueueTags(['main_page_10']);
        $queue->enqueueUrl('https://example.org/a');
        $subject->purgeEverything(PurgeSource::CACHE_FLUSH_EVENT);

        $payload = $queue->drain();
        self::assertSame([], $payload->tags());
        self::assertSame([], $payload->urls());
        self::assertSame(['*'], $payload->fullSiteFlushes());
        self::assertSame('*', $dispatcher->events[0]->getSite());
        self::assertSame('*', $logger->records[0]['context']['site']);
    }

    #[Test]
    public function purgeTagsRejectsInvalidTagInput(): void
    {
        $subject = new PurgeService($this->createQueue(), new CollectingDispatcher(), new InMemoryLogger());

        $this->expectException(InvalidArgumentException::class);
        $subject->purgeTags(['main_page_1', ''], PurgeSource::API);
    }

    #[Test]
    public function purgeTagsRejectsAssociativeTagInput(): void
    {
        $subject = new PurgeService($this->createQueue(), new CollectingDispatcher(), new InMemoryLogger());

        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore-next-line This negative test intentionally violates the public list contract.
        $subject->purgeTags(['first' => 'main_page_1'], PurgeSource::API);
    }

    #[Test]
    public function purgeUrlRejectsNonAbsoluteUrls(): void
    {
        $subject = new PurgeService($this->createQueue(), new CollectingDispatcher(), new InMemoryLogger());

        $this->expectException(InvalidArgumentException::class);
        $subject->purgeUrl('/relative/path', PurgeSource::API);
    }

    #[Test]
    public function purgeUrlRejectsNonHttpSchemes(): void
    {
        $subject = new PurgeService($this->createQueue(), new CollectingDispatcher(), new InMemoryLogger());

        $this->expectException(InvalidArgumentException::class);
        $subject->purgeUrl('ftp://example.org/path', PurgeSource::API);
    }

    #[Test]
    public function dispatchExceptionStillWritesStructuredLog(): void
    {
        $logger = new InMemoryLogger();
        $subject = new PurgeService($this->createQueue(), new ThrowingDispatcher(), $logger);

        try {
            $subject->purgeTags(['main_page_1'], PurgeSource::API);
            self::fail('Expected dispatcher exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('listener failed', $exception->getMessage());
        }

        self::assertCount(1, $logger->records);
        self::assertSame('enqueued', $logger->records[0]['context']['outcome']);
    }

    private function createQueue(): PurgeQueue
    {
        return new PurgeQueue(new class implements TagNamespaceResolverInterface
        {
            public function resolveForSite(string $siteIdentifier): string
            {
                return $siteIdentifier;
            }
        });
    }
}

final class CollectingDispatcher implements EventDispatcherInterface
{
    /**
     * @var list<PurgeIssuedEvent>
     */
    public array $events = [];

    public function dispatch(object $event): object
    {
        if ($event instanceof PurgeIssuedEvent) {
            $this->events[] = $event;
        }

        return $event;
    }
}

final class ThrowingDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        throw new \RuntimeException('listener failed', 5682165125);
    }
}

final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}
