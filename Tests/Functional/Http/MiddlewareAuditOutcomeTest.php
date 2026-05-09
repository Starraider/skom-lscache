<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Audit\PurgeLogRow;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MiddlewareAuditOutcomeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function queuedFullSiteFlushPromotesAllMatchingAuditRowsToFlushed(): void
    {
        $this->clearAuditLogRows();
        $this->clearPurgeQueue();

        /** @var PurgeServiceInterface $service */
        $service = $this->get(PurgeServiceInterface::class);
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);

        $service->purgeFullSite('main', PurgeSource::API, 'manual-a');
        $service->purgeFullSite('main', PurgeSource::MODULE, 'manual-b');

        $middleware = $this->get(SkomLscacheMiddleware::class);
        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 300, 'tagNamespace' => 'main']]),
            new AuditFixedResponseHandler(new Response()),
        );

        self::assertSame('tag=main_*', $response->getHeaderLine('X-LiteSpeed-Purge'));

        $rows = $repository->findRecentBySite('main', 10, 0);
        self::assertCount(2, $rows);
        self::assertSame(['manual-b', 'manual-a'], array_map(
            static fn (PurgeLogRow $row): ?string => $row->triggerRef,
            $rows,
        ));
        self::assertSame(
            [PurgeLogOutcome::FLUSHED, PurgeLogOutcome::FLUSHED],
            array_map(static fn (PurgeLogRow $row): PurgeLogOutcome => $row->outcome, $rows),
        );
    }

    #[Test]
    public function truncatedFlushPromotesAuditRowsToTruncated(): void
    {
        $this->clearAuditLogRows();
        $this->clearPurgeQueue();

        /** @var PurgeServiceInterface $service */
        $service = $this->get(PurgeServiceInterface::class);
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);

        $tags = [];
        for ($i = 1; $i <= 70; $i++) {
            $tags[] = 'main_page_' . $i;
        }

        $service->purgeTags($tags, PurgeSource::API, 'manual-truncated');

        $middleware = $this->get(SkomLscacheMiddleware::class);
        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 300, 'tagNamespace' => 'main']]),
            new AuditFixedResponseHandler(new Response()),
        );

        self::assertTrue($response->hasHeader('X-LiteSpeed-Purge'));

        $rows = $repository->findRecentBySite('unknown', 10, 0);
        self::assertCount(1, $rows);
        self::assertSame(PurgeLogOutcome::TRUNCATED, $rows[0]->outcome);
        self::assertSame(70, $rows[0]->tagCount);
        self::assertCount(70, $rows[0]->tags);
    }

    #[Test]
    public function fiveHundredResponseKeepsAuditRowsEnqueued(): void
    {
        $this->clearAuditLogRows();
        $this->clearPurgeQueue();

        /** @var PurgeServiceInterface $service */
        $service = $this->get(PurgeServiceInterface::class);
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');

        $service->purgeTags(['main_page_42'], PurgeSource::API, 'manual-5xx');

        $middleware = $this->get(SkomLscacheMiddleware::class);
        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 300, 'tagNamespace' => 'main']]),
            new AuditFixedResponseHandler(new Response('php://temp', 503)),
        );

        self::assertFalse($response->hasHeader('X-LiteSpeed-Purge'));
        self::assertSame(['main_page_42'], $queue->drain()->tags());

        $rows = $repository->findRecentBySite('unknown', 10, 0);
        self::assertCount(1, $rows);
        self::assertSame(PurgeLogOutcome::ENQUEUED, $rows[0]->outcome);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createRequest(array $settings = []): ServerRequest
    {
        $site = new Site(
            'main',
            1,
            ['base' => 'https://example.org/', 'languages' => [[
                'languageId' => 0,
                'base' => '/',
                'title' => 'Default',
                'navigationTitle' => 'Default',
                'flag' => 'us',
                'locale' => 'en_US.UTF-8',
            ]]],
            SiteSettings::createFromSettingsTree($settings),
        );

        return (new ServerRequest(new Uri('https://example.org/public'), 'GET', null, [], [
            'SERVER_SOFTWARE' => 'Apache',
        ]))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('routing', new PageArguments(42, '0', []));
    }

    private function clearAuditLogRows(): void
    {
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $repository->purgeOlderThan(new \DateTimeImmutable('@32503680000'));
    }

    private function clearPurgeQueue(): void
    {
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');
        $queue->drain();
    }
}

final class AuditFixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
