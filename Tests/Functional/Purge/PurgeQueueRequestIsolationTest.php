<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\PurgeQueue;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PurgeQueueRequestIsolationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function firstRequestLifecycleCanAccumulateAndDrainPayload(): void
    {
        $queue = $this->getPurgeQueue();
        $queue->enqueueTags(['main_page_42']);
        $queue->enqueueUrl('https://example.org/first');

        $payload = $queue->drain();

        self::assertSame(['main_page_42'], $payload->tags());
        self::assertSame(['https://example.org/first'], $payload->urls());
    }

    #[Test]
    public function secondRequestLifecycleStartsWithAnEmptyQueue(): void
    {
        $queue = $this->getPurgeQueue();

        $payload = $queue->drain();

        self::assertSame([], $payload->tags());
        self::assertSame([], $payload->urls());
        self::assertSame([], $payload->fullSiteFlushes());
    }

    private function getPurgeQueue(): PurgeQueue
    {
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');

        return $queue;
    }
}
