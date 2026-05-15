<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Controller;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Skom\Lscache\Backend\Probe\CacheProbeClientInterface;
use Skom\Lscache\Backend\Probe\CacheProbeResult;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
final readonly class ProbeController
{
    public function __construct(
        private CacheProbeClientInterface $cacheProbeClient,
        private SiteFinder $siteFinder,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->languageServiceFactory->createForBackendUser();
        $siteIdentifier = $this->resolveSiteIdentifier($request);
        $site = $this->resolveSite($siteIdentifier);
        $targetUrl = $this->resolveTargetUrl($request, $site);

        if ($targetUrl === '') {
            return new JsonResponse(
                CacheProbeResult::fromThrowable(
                    '',
                    new InvalidArgumentException('Missing target URL for cache probe.'),
                )->toArray($languageService),
            );
        }

        return new JsonResponse(
            $this->cacheProbeClient->probe($targetUrl)->toArray($languageService),
        );
    }

    private function resolveTargetUrl(ServerRequestInterface $request, ?Site $site): string
    {
        $parsedBody = $request->getParsedBody();
        $bodyData = is_array($parsedBody) ? $parsedBody : [];
        $queryData = $request->getQueryParams();
        $targetUrl = trim((string)($bodyData['target'] ?? $queryData['target'] ?? ''));

        if ($targetUrl === '') {
            return $site instanceof Site
                ? rtrim((string)$site->getBase(), '/') . '/'
                : '';
        }

        if (!$this->looksAbsolute($targetUrl) && $site instanceof Site) {
            return rtrim((string)$site->getBase(), '/') . '/' . ltrim($targetUrl, '/');
        }

        return $targetUrl;
    }

    private function resolveSiteIdentifier(ServerRequestInterface $request): string
    {
        $parsedBody = $request->getParsedBody();
        $bodyData = is_array($parsedBody) ? $parsedBody : [];
        $queryData = $request->getQueryParams();

        return trim((string)($bodyData['site'] ?? $queryData['site'] ?? ''));
    }

    private function resolveSite(string $siteIdentifier): ?Site
    {
        if ($siteIdentifier === '') {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return null;
        }
    }

    private function looksAbsolute(string $targetUrl): bool
    {
        return str_starts_with($targetUrl, '//')
            || (bool)preg_match('#^[a-z][a-z0-9+.-]*://#i', $targetUrl);
    }

}
