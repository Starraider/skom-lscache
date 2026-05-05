<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Http\Middleware\SkomLscacheMiddleware;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MiddlewareOrderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function middlewareIsRegisteredBetweenFrontendAuthenticationAndTsfePreparation(): void
    {
        $configuration = require __DIR__ . '/../../../Configuration/RequestMiddlewares.php';

        self::assertArrayHasKey('frontend', $configuration);
        self::assertArrayHasKey('skom/lscache/response-header', $configuration['frontend']);

        $middleware = $configuration['frontend']['skom/lscache/response-header'];
        self::assertSame(SkomLscacheMiddleware::class, $middleware['target']);
        self::assertContains(
            'typo3/cms-frontend/authentication',
            $middleware['after'] ?? [],
            'LSCache response middleware must run after typo3/cms-frontend/authentication.',
        );
        self::assertContains(
            'typo3/cms-frontend/prepare-tsfe-rendering',
            $middleware['before'] ?? [],
            'LSCache response middleware must run before typo3/cms-frontend/prepare-tsfe-rendering.',
        );
    }
}
