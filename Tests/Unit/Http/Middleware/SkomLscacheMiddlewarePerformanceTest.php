<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Skom\Lscache\Http\BypassReason;
use Skom\Lscache\Http\CacheabilityClassifierInterface;
use Skom\Lscache\Http\CacheabilityDecision;
use Skom\Lscache\Http\HeaderEmissionDecider;
use Skom\Lscache\Http\HeaderWriter;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
use Skom\Lscache\Http\OpenLiteSpeedDetector;
use Skom\Lscache\Http\TagSchema;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class SkomLscacheMiddlewarePerformanceTest extends TestCase
{
    #[Test]
    public function nonCacheablePathStaysBelowFiveMillisecondsP95OverFiveHundredIterations(): void
    {
        $middleware = new SkomLscacheMiddleware(
            new HeaderEmissionDecider(),
            new NonCacheableClassifier(),
            new HeaderWriter(),
            new TagSchema(new NullLogger()),
            new PerformancePassthroughDispatcher(),
            new OpenLiteSpeedDetector(new NullLogger()),
        );
        $request = $this->createRequest();
        $handler = new PerformanceFixedResponseHandler(new Response());
        $durations = [];

        for ($i = 0; $i < 500; $i++) {
            $start = hrtime(true);
            $middleware->process($request, $handler);
            $durations[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($durations);
        $p95 = $durations[(int)floor((count($durations) - 1) * 0.95)];

        self::assertLessThanOrEqual(
            5.0,
            $p95,
            sprintf('Non-cacheable middleware overhead p95 must be <= 5 ms over 500 iterations, got %.3f ms.', $p95),
        );
    }

    private function createRequest(): ServerRequest
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
            SiteSettings::createFromSettingsTree(['lscache' => ['enabled' => true]]),
        );

        return (new ServerRequest(new Uri('https://example.org/private'), 'GET', null, [], [
            'SERVER_SOFTWARE' => 'OpenLiteSpeed',
        ]))
            ->withAttribute('site', $site)
            ->withAttribute('routing', new PageArguments(42, '0', []));
    }
}

final class NonCacheableClassifier implements CacheabilityClassifierInterface
{
    public function classify(ServerRequestInterface $request, ResponseInterface $response): CacheabilityDecision
    {
        return CacheabilityDecision::bypass(BypassReason::RESPONSE_SET_COOKIE);
    }
}

final class PerformancePassthroughDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}

final class PerformanceFixedResponseHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}
