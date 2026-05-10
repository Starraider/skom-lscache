<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Skom\Lscache\Purge\Audit\PurgeAuditListener;
use Skom\Lscache\Purge\Audit\PurgeAuditRequestContext;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\Event\PurgeIssuedEvent;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PurgeAuditListenerFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function dispatchingAFullSitePurgePersistsOneEnqueuedAuditRow(): void
    {
        $this->clearAuditLogRows();
        $this->clearPurgeQueue();

        /** @var PurgeServiceInterface $service */
        $service = $this->get(PurgeServiceInterface::class);
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);

        $service->purgeFullSite('main', PurgeSource::API, 'functional:test');

        $rows = $repository->findRecentBySite('main', 10, 0);

        self::assertCount(1, $rows);
        self::assertSame('main', $rows[0]->siteIdentifier);
        self::assertSame(PurgeSource::API, $rows[0]->source);
        self::assertSame([], $rows[0]->tags);
        self::assertSame([], $rows[0]->urls);
        self::assertSame(PurgeLogOutcome::ENQUEUED, $rows[0]->outcome);
        self::assertSame('functional:test', $rows[0]->triggerRef);
    }

    #[Test]
    public function listenerLogsDatabaseFailuresWithoutThrowing(): void
    {
        $logger = new InMemoryLogger();
        $repository = new PurgeLogRepository(
            new class extends ConnectionPool {
                public function getConnectionForTable(string $tableName): Connection
                {
                    return new class extends Connection {
                        /** @phpstan-ignore-next-line */
                        public function __construct()
                        {
                        }

                        public function insert(string $tableName, array $data, array $types = []): int
                        {
                            throw new class('forced failure', 7350633865) extends \RuntimeException implements \Doctrine\DBAL\Exception {
                            };
                        }
                    };
                }
            },
        );
        $listener = new PurgeAuditListener(
            $repository,
            new PurgeAuditRequestContext(),
            $logger,
        );

        $listener(
            new PurgeIssuedEvent(
                'main',
                PurgeSource::CLI,
                ['main_page_1'],
                [],
                'manual:test',
                'request-123',
                time(),
            ),
        );

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('audit-persist-failed', $logger->records[0]['message']);
        self::assertSame('audit-persist-failed', $logger->records[0]['context']['outcome']);
        self::assertSame('request-123', $logger->records[0]['context']['requestId']);
    }

    private function clearAuditLogRows(): void
    {
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $repository->purgeOlderThan(new \DateTimeImmutable('@32503680000'));
    }

    private function clearPurgeQueue(): void
    {
        $this->get('skom.lscache.purge_queue')->drain();
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
