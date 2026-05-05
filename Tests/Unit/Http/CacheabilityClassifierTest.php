<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Http\BypassReason;
use Skom\Lscache\Http\CacheabilityClassifier;
use Skom\Lscache\Http\MissingRequestContext;
use Skom\Lscache\Http\PageContentInventory;
use Skom\Lscache\Http\PageContentInventoryProvider;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

#[AllowMockObjectsWithoutExpectations]
final class CacheabilityClassifierTest extends TestCase
{
    #[Test]
    public function returnsBackendContextForBackendRequests(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news', [], true),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::BACKEND_CONTEXT, $decision->reason);
    }

    #[Test]
    public function returnsMissingRequestContextWhenSiteAttributeIsAbsent(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news', withSite: false),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::MISSING_REQUEST_CONTEXT, $decision->reason);
    }

    #[Test]
    public function returnsFrontendUserWhenUserIsLoggedInAndBypassEnabled(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news', ['lscache' => ['bypass' => ['loggedInFE' => true]]], false, true),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::FRONTEND_USER, $decision->reason);
    }

    #[Test]
    public function doesNotBypassFrontendUserWhenSettingIsDisabled(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news', ['lscache' => ['bypass' => ['loggedInFE' => false]]], false, true),
            new Response(),
        );

        self::assertTrue($decision->cacheable);
        self::assertSame(BypassReason::CACHEABLE, $decision->reason);
    }

    #[Test]
    public function returnsSetCookieReasonWhenSetCookieExistsAndBypassEnabled(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news'),
            new Response('php://temp', 200, ['Set-Cookie' => 'foo=bar']),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::RESPONSE_SET_COOKIE, $decision->reason);
    }

    #[Test]
    public function doesNotBypassSetCookieWhenSettingIsDisabled(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news', ['lscache' => ['bypass' => ['setCookie' => false]]]),
            new Response('php://temp', 200, ['Set-Cookie' => 'foo=bar']),
        );

        self::assertTrue($decision->cacheable);
        self::assertSame(BypassReason::CACHEABLE, $decision->reason);
    }

    #[Test]
    public function rejectsUnsupportedRequestMethodsCaseInsensitively(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('post', '/news'),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::REQUEST_METHOD, $decision->reason);
    }

    #[Test]
    public function returnsFirstMatchingBypassPathRule(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/shop/private/item', ['lscache' => ['bypass' => ['paths' => ['/shop/*', '/shop/private/*']]]]),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::BYPASS_PATH, $decision->reason);
        self::assertSame('/shop/*', $decision->matchedRule);
    }

    #[Test]
    public function matchesBypassPluginSignatureFromInventory(): void
    {
        $classifier = $this->createClassifier(new PageContentInventory(['news_pi1'], ['textmedia']));

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news', ['lscache' => ['bypass' => ['pluginSignatures' => ['news_pi1']]]]),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::BYPASS_PLUGIN_SIGNATURE, $decision->reason);
        self::assertSame('news_pi1', $decision->matchedRule);
    }

    #[Test]
    public function matchesBypassCTypeFromInventory(): void
    {
        $classifier = $this->createClassifier(new PageContentInventory([], ['list']));

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news', ['lscache' => ['bypass' => ['ctypes' => ['list']]]]),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::BYPASS_CTYPE, $decision->reason);
        self::assertSame('list', $decision->matchedRule);
    }

    #[Test]
    public function returnsCacheableForAnonymousPublicGetWithoutBypassMatches(): void
    {
        $classifier = $this->createClassifier(PageContentInventory::empty());

        $decision = $classifier->classify(
            $this->createRequest('GET', '/public'),
            new Response(),
        );

        self::assertTrue($decision->cacheable);
        self::assertSame(BypassReason::CACHEABLE, $decision->reason);
        self::assertNull($decision->matchedRule);
    }

    #[Test]
    public function returnsMissingRequestContextWhenInventoryProviderCannotResolveRequestContext(): void
    {
        $provider = new class() implements PageContentInventoryProvider
        {
            public function getForRequest(\Psr\Http\Message\ServerRequestInterface $request): PageContentInventory
            {
                throw MissingRequestContext::forAttribute('routing');
            }
        };

        $context = new Context();
        $context->setAspect('frontend.user', new UserAspect());
        $classifier = new CacheabilityClassifier($provider, $context);

        $decision = $classifier->classify(
            $this->createRequest('GET', '/news'),
            new Response(),
        );

        self::assertFalse($decision->cacheable);
        self::assertSame(BypassReason::MISSING_REQUEST_CONTEXT, $decision->reason);
    }

    private function createClassifier(PageContentInventory $inventory): CacheabilityClassifier
    {
        $provider = new class($inventory) implements PageContentInventoryProvider
        {
            public function __construct(private readonly PageContentInventory $inventory) {}

            public function getForRequest(\Psr\Http\Message\ServerRequestInterface $request): PageContentInventory
            {
                return $this->inventory;
            }
        };

        $context = new Context();
        $context->setAspect('frontend.user', new UserAspect());

        return new CacheabilityClassifier($provider, $context);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createRequest(
        string $method,
        string $path,
        array $settings = [],
        bool $backend = false,
        bool $loggedInFrontendUser = false,
        bool $withSite = true,
    ): ServerRequest {
        $siteSettings = SiteSettings::createFromSettingsTree($settings);
        $site = null;
        if ($withSite) {
            $site = new \TYPO3\CMS\Core\Site\Entity\Site(
                'test',
                1,
                ['base' => 'https://example.org/', 'languages' => [[
                    'languageId' => 0,
                    'base' => '/',
                    'title' => 'Default',
                    'navigationTitle' => 'Default',
                    'flag' => 'us',
                    'locale' => 'en_US.UTF-8',
                ]]],
                $siteSettings,
            );
        }

        $frontendUser = null;
        if ($loggedInFrontendUser) {
            $frontendUser = $this->createMock(FrontendUserAuthentication::class);
            $frontendUser->user = ['uid' => 123];
        }

        $request = new ServerRequest(new Uri('https://example.org' . $path), $method);
        $request = $request->withAttribute(
            'applicationType',
            $backend ? SystemEnvironmentBuilder::REQUESTTYPE_BE : SystemEnvironmentBuilder::REQUESTTYPE_FE,
        );
        if ($site !== null) {
            $request = $request->withAttribute('site', $site);
        }

        return $request->withAttribute('frontend.user', $frontendUser);
    }
}
