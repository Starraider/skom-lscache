<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
use Skom\Lscache\Purge\PurgeQueue;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MiddlewareHeaderEmissionTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function cacheableFrontendResponseContainsExpectedLscacheHeaders(): void
    {
        $middleware = $this->get(SkomLscacheMiddleware::class);

        $response = $middleware->process(
            $this->createRequest([
                'lscache' => [
                    'defaultTtl' => 300,
                    'tagNamespace' => 'main',
                    'vary' => ['cookieAllowlist' => ['fe_typo_user']],
                ],
            ]),
            new FunctionalFixedResponseHandler(new Response()),
        );

        self::assertSame('public,max-age=300', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertSame('main_site,main_page_42', $response->getHeaderLine('X-LiteSpeed-Tag'));
        self::assertSame('cookie=fe_typo_user', $response->getHeaderLine('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function cookieBearingFrontendResponseIsPrivateAndHasNoTagOrVary(): void
    {
        $middleware = $this->get(SkomLscacheMiddleware::class);

        $response = $middleware->process(
            $this->createRequest(),
            new FunctionalFixedResponseHandler(new Response('php://temp', 200, ['Set-Cookie' => 'foo=bar'])),
        );

        self::assertSame('no-cache, private', $response->getHeaderLine('X-LiteSpeed-Cache-Control'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Tag'));
        self::assertFalse($response->hasHeader('X-LiteSpeed-Vary'));
    }

    #[Test]
    public function queuedPayloadIsFlushedViaResponsePiggybackHeader(): void
    {
        $queue = $this->getQueue();
        $queue->enqueueTags(['main_page_42']);
        $queue->enqueueUrl('https://example.org/news');

        $middleware = $this->get(SkomLscacheMiddleware::class);
        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 300, 'tagNamespace' => 'main']]),
            new FunctionalFixedResponseHandler(new Response()),
        );

        self::assertSame(
            'tag=main_page_42,url=https://example.org/news',
            $response->getHeaderLine('X-LiteSpeed-Purge'),
        );
        self::assertSame([], $queue->drain()->tags());
    }

    #[Test]
    public function fiveHundredResponseDoesNotDrainQueueBeforeFlushPoint(): void
    {
        $queue = $this->getQueue();
        $queue->enqueueTags(['main_page_42']);

        $middleware = $this->get(SkomLscacheMiddleware::class);
        $response = $middleware->process(
            $this->createRequest(['lscache' => ['defaultTtl' => 300, 'tagNamespace' => 'main']]),
            new FunctionalFixedResponseHandler(new Response('php://temp', 503)),
        );

        self::assertFalse($response->hasHeader('X-LiteSpeed-Purge'));
        self::assertSame(['main_page_42'], $queue->drain()->tags());
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

    private function getQueue(): PurgeQueue
    {
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');

        return $queue;
    }
}

final class FunctionalFixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
