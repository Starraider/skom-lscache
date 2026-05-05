<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
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
}

final class FunctionalFixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
