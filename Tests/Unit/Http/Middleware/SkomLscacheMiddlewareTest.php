<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Skom\Lscache\Http\CacheabilityClassifierInterface;
use Skom\Lscache\Http\CacheabilityDecision;
use Skom\Lscache\Http\Event\CacheControlOverrideEvent;
use Skom\Lscache\Http\HeaderEmissionDecider;
use Skom\Lscache\Http\HeaderWriter;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
use Skom\Lscache\Http\OpenLiteSpeedDetector;
use Skom\Lscache\Http\TagSchema;
use Skom\Lscache\Purge\PurgeHeaderSerializer;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\TagNamespaceResolverInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class SkomLscacheMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        OpenLiteSpeedDetector::resetNoticeForTests();
    }

    #[Test]
    public function disabledSitePassesThroughWithoutClassifierCallOrHeaders(): void
    {
        $classifier = new CountingClassifier(CacheabilityDecision::cacheable());
        $middleware = $this->createMiddleware($classifier);

        $response = $middleware->process(
            $this->createRequest(['lscache' => ['enabled' => false]]),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame(0, $classifier->calls);
        self::assertFalse($response->hasHeader('X-LiteSpeed-Cache-Control'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Tag'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function cacheableDecisionWritesControlTagsAndVary(): void
    {
        $middleware = $this->createMiddleware(new CountingClassifier(CacheabilityDecision::cacheable()));

        $response = $middleware->process(
            $this->createRequest([
                'lscache' => [
                    'defaultTtl' => 900,
                    'tagNamespace' => 'siteA',
                    'vary' => ['cookieAllowlist' => ['fe_typo_user', 'preview']],
                ],
            ]),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame('public,max-age=900', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertSame('siteA_site,siteA_page_42', $response->getHeaderLine('X-LiteSpeed-Tag'));
        self::assertSame('cookie=fe_typo_user,cookie=preview', $response->getHeaderLine('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function piggybackFlushAddsPurgeHeaderFromQueuedPayload(): void
    {
        $queue = $this->createQueue();
        $queue->enqueueTags(['main_page_10']);
        $queue->enqueueUrl('https://example.org/news');
        $queue->enqueueFullSiteFlush('main');

        $middleware = $this->createMiddleware(new CountingClassifier(CacheabilityDecision::cacheable()), queue: $queue);

        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 900, 'tagNamespace' => 'siteA']]),
            new FixedResponseHandler(new Response()),
        );

        self::assertTrue($response->hasHeader('X-LiteSpeed-Purge'));
        self::assertSame('tag=main_*,url=https://example.org/news', $response->getHeaderLine('X-LiteSpeed-Purge'));
        self::assertSame([], $queue->drain()->tags());
    }

    #[Test]
    public function failedResponseSkipsFlushAndKeepsQueueForFallbackHandling(): void
    {
        $queue = $this->createQueue();
        $queue->enqueueTags(['main_page_55']);

        $middleware = $this->createMiddleware(
            new CountingClassifier(CacheabilityDecision::cacheable()),
            queue: $queue,
        );

        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 900, 'tagNamespace' => 'siteA']]),
            new FixedResponseHandler(new Response('php://temp', 503)),
        );

        self::assertFalse($response->hasHeader('X-LiteSpeed-Purge'));
        self::assertSame(['main_page_55'], $queue->drain()->tags());
    }

    #[Test]
    public function invalidCookieTokensAreFilteredFromVaryHeader(): void
    {
        $middleware = $this->createMiddleware(new CountingClassifier(CacheabilityDecision::cacheable()));

        $response = $middleware->process(
            $this->createRequest([
                'lscache' => [
                    'defaultTtl' => 900,
                    'tagNamespace' => 'siteA',
                    'vary' => ['cookieAllowlist' => ['fe_typo_user', 'bad,cookie', 'bad cookie', 'ctl()', 'preview']],
                ],
            ]),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame('cookie=fe_typo_user,cookie=preview', $response->getHeaderLine('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function setCookieResponseUsesClassifierPathAndBecomesPrivate(): void
    {
        $classifier = new CountingClassifier(CacheabilityDecision::bypass(\Skom\Lscache\Http\BypassReason::RESPONSE_SET_COOKIE));
        $middleware = $this->createMiddleware($classifier);

        $response = $middleware->process(
            $this->createRequest(),
            new FixedResponseHandler(new Response('php://temp', 200, ['Set-Cookie' => 'foo=bar'])),
        );

        self::assertSame(1, $classifier->calls);
        self::assertSame('no-cache, private', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Tag'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function missingSiteFailsClosedWithoutClassifierCall(): void
    {
        $classifier = new CountingClassifier(CacheabilityDecision::cacheable());
        $middleware = $this->createMiddleware($classifier);

        $response = $middleware->process(
            (new ServerRequest(new Uri('https://example.org/public'), 'GET'))
                ->withAttribute('routing', new PageArguments(42, '0', [])),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame(0, $classifier->calls);
        self::assertSame('no-cache, private', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
    }

    #[Test]
    public function overrideEventCanReplaceDefaultTtl(): void
    {
        $middleware = $this->createMiddleware(
            new CountingClassifier(CacheabilityDecision::cacheable()),
            new OverrideDispatcher(120),
        );

        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 900, 'tagNamespace' => 'siteA']]),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame('public,max-age=120', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
    }

    #[Test]
    public function invalidDefaultTtlFailsClosedBeforeCacheableHeaderIsWritten(): void
    {
        $middleware = $this->createMiddleware(new CountingClassifier(CacheabilityDecision::cacheable()));

        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 0, 'tagNamespace' => 'siteA']]),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame('no-cache, private', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Tag'));
    }

    #[Test]
    public function invalidOverrideTtlFailsClosedBeforeCacheableHeaderIsWritten(): void
    {
        $middleware = $this->createMiddleware(
            new CountingClassifier(CacheabilityDecision::cacheable()),
            new OverrideDispatcher(0),
        );

        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 900, 'tagNamespace' => 'siteA']]),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame('no-cache, private', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Tag'));
    }

    #[Test]
    public function missingRoutingFailsClosed(): void
    {
        $middleware = $this->createMiddleware(new CountingClassifier(CacheabilityDecision::cacheable()));

        $response = $middleware->process(
            $this->createRequest()->withoutAttribute('routing'),
            new FixedResponseHandler(new Response()),
        );

        self::assertSame('no-cache, private', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Tag'));
    }

    #[Test]
    public function nonOpenLiteSpeedNoticeIsLoggedOnlyOnce(): void
    {
        $logger = new InMemoryLogger();
        $middleware = $this->createMiddleware(new CountingClassifier(CacheabilityDecision::cacheable()), logger: $logger);

        $request = $this->createRequest(['lscache' => ['tagNamespace' => 'siteA']]);
        $middleware->process($request, new FixedResponseHandler(new Response()));
        $middleware->process($request, new FixedResponseHandler(new Response()));

        self::assertCount(3, $logger->records);
        self::assertSame('notice', $logger->records[0]['level']);
        self::assertSame('non-ols-degraded', $logger->records[0]['context']['outcome']);
    }

    private function createMiddleware(
        CacheabilityClassifierInterface $classifier,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?InMemoryLogger $logger = null,
        ?PurgeQueue $queue = null,
    ): SkomLscacheMiddleware {
        $logger ??= new InMemoryLogger();

        $namespaceResolver = new class implements TagNamespaceResolverInterface
        {
            public function resolveForSite(string $siteIdentifier): string
            {
                return $siteIdentifier;
            }
        };

        return new SkomLscacheMiddleware(
            new HeaderEmissionDecider(),
            $classifier,
            new HeaderWriter(),
            new TagSchema($logger),
            $eventDispatcher ?? new PassthroughDispatcher(),
            new OpenLiteSpeedDetector($logger),
            $queue ?? new PurgeQueue($namespaceResolver),
            new PurgeHeaderSerializer(),
            $namespaceResolver,
            $logger,
        );
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
            ->withAttribute('site', $site)
            ->withAttribute('routing', new PageArguments(42, '0', []));
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

final class CountingClassifier implements CacheabilityClassifierInterface
{
    public int $calls = 0;

    public function __construct(private readonly CacheabilityDecision $decision) {}

    public function classify(ServerRequestInterface $request, ResponseInterface $response): CacheabilityDecision
    {
        $this->calls++;

        return $this->decision;
    }
}

final class FixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

final class PassthroughDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}

final class OverrideDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly int $ttl) {}

    public function dispatch(object $event): object
    {
        if ($event instanceof CacheControlOverrideEvent) {
            $event->setOverrideTtl($this->ttl);
        }

        return $event;
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
