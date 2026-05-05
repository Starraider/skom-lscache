<?php

declare(strict_types=1);

namespace Skom\Lscache\Http\Middleware;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Http\CacheabilityClassifierInterface;
use Skom\Lscache\Http\Event\CacheControlOverrideEvent;
use Skom\Lscache\Http\HeaderEmissionDecider;
use Skom\Lscache\Http\HeaderWriter;
use Skom\Lscache\Http\OpenLiteSpeedDetector;
use Skom\Lscache\Http\TagSchema;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * TYPO3 frontend middleware that emits LSCache response headers after downstream rendering.
 */
final readonly class SkomLscacheMiddleware implements MiddlewareInterface
{
    /**
     * @param HeaderEmissionDecider $headerEmissionDecider Decides whether LSCache headers are enabled for the site.
     * @param CacheabilityClassifierInterface $cacheabilityClassifier Classifies downstream responses as cacheable or private.
     * @param HeaderWriter $headerWriter Writes all LSCache response headers.
     * @param TagSchema $tagSchema Computes deterministic LSCache tags.
     * @param EventDispatcherInterface $eventDispatcher Dispatches TTL override events.
     * @param OpenLiteSpeedDetector $openLiteSpeedDetector Detects/logs non-OLS graceful degradation.
     */
    public function __construct(
        private HeaderEmissionDecider $headerEmissionDecider,
        private CacheabilityClassifierInterface $cacheabilityClassifier,
        private HeaderWriter $headerWriter,
        private TagSchema $tagSchema,
        private EventDispatcherInterface $eventDispatcher,
        private OpenLiteSpeedDetector $openLiteSpeedDetector,
    ) {}

    /**
     * Handles a frontend request, lets downstream TYPO3 rendering finish, then writes LSCache headers.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with TYPO3 site and routing attributes.
     * @param RequestHandlerInterface $handler Next middleware/request handler in the TYPO3 stack.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $this->headerWriter->writePrivate($handler->handle($request));
        }

        $siteSettings = $site->getSettings();
        if (!$this->headerEmissionDecider->shouldEmitForSite($siteSettings)) {
            return $handler->handle($request);
        }

        $response = $handler->handle($request);
        $this->openLiteSpeedDetector->logGracefulDegradationIfMissing($request);

        $decision = $this->cacheabilityClassifier->classify($request, $response);
        if (!$decision->cacheable) {
            return $this->headerWriter->writePrivate($response);
        }

        $ttl = $this->resolveTtl($request, $response, $siteSettings);
        $pageUid = $this->resolvePageUid($request);
        if ($ttl === null || $pageUid === null) {
            return $this->headerWriter->writePrivate($response);
        }

        $namespace = trim((string)$this->getSetting($siteSettings, 'lscache.tagNamespace', ''));
        if ($namespace === '') {
            $namespace = $site->getIdentifier();
        }

        $tagSet = $this->tagSchema->computeTagsForPage($site->getIdentifier(), $namespace, $pageUid, []);

        return $this->headerWriter->writeCacheable(
            $response,
            $ttl,
            $tagSet->tags,
            $this->resolveCookieAllowlist($siteSettings),
        );
    }

    /**
     * Resolves the final cache TTL from site settings and optional PSR-14 override listeners.
     *
     * @param ServerRequestInterface $request Current PSR-7 request being processed.
     * @param ResponseInterface $response Downstream response before LSCache headers are written.
     * @param SiteSettings $siteSettings TYPO3 site settings for the current site.
     */
    private function resolveTtl(
        ServerRequestInterface $request,
        ResponseInterface $response,
        SiteSettings $siteSettings,
    ): ?int {
        $defaultTtl = $this->getSetting($siteSettings, 'lscache.defaultTtl', 0);
        if (!is_int($defaultTtl) || $defaultTtl <= 0) {
            return null;
        }

        try {
            $event = $this->eventDispatcher->dispatch(
                new CacheControlOverrideEvent($request, $response, $siteSettings, $defaultTtl),
            );
        } catch (InvalidArgumentException) {
            return null;
        }
        if (!$event instanceof CacheControlOverrideEvent || $event->getOverride() === null) {
            return $defaultTtl;
        }

        return $event->getOverride()->ttl;
    }

    /**
     * Resolves the positive page UID from TYPO3 routing request attributes.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with a `routing` PageArguments attribute.
     */
    private function resolvePageUid(ServerRequestInterface $request): ?int
    {
        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            return null;
        }

        $pageUid = $routing->getPageId();

        return $pageUid > 0 ? $pageUid : null;
    }

    /**
     * Resolves configured cookie names for `X-LiteSpeed-Vary`.
     *
     * @param SiteSettings $siteSettings TYPO3 site settings for the current site.
     * @return list<string>
     */
    private function resolveCookieAllowlist(SiteSettings $siteSettings): array
    {
        $allowlist = $this->getSetting($siteSettings, 'lscache.vary.cookieAllowlist', []);
        if (!is_array($allowlist)) {
            return [];
        }

        $cookies = [];
        foreach ($allowlist as $cookie) {
            $cookie = trim((string)$cookie);
            if ($cookie !== '' && $this->isValidHeaderToken($cookie)) {
                $cookies[] = $cookie;
            }
        }

        return $cookies;
    }

    /**
     * Reads a site setting from flat dotted keys or nested settings arrays.
     *
     * @param SiteSettings $siteSettings TYPO3 site settings for the current site.
     * @param string $path Dotted setting path, for example `lscache.defaultTtl`.
     * @param mixed $default Fallback value when the setting is absent.
     */
    private function getSetting(SiteSettings $siteSettings, string $path, mixed $default): mixed
    {
        $value = $siteSettings->get($path, null);
        if ($value !== null) {
            return $value;
        }

        $nested = $siteSettings->getAll();
        foreach (explode('.', $path) as $part) {
            if (!is_array($nested) || !array_key_exists($part, $nested)) {
                return $default;
            }
            $nested = $nested[$part];
        }

        return $nested;
    }

    /**
     * Validates one RFC 7230 header token.
     *
     * @param string $token Candidate token value.
     */
    private function isValidHeaderToken(string $token): bool
    {
        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $token) === 1;
    }
}
