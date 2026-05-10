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
use TYPO3\CMS\Core\Localization\LanguageService;
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

        if ($this->looksAbsolute($targetUrl) && !$this->isAllowedAbsoluteTarget($targetUrl, $site)) {
            return new JsonResponse(
                $this->buildDisallowedTargetPayload($languageService, $targetUrl),
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

    private function isAllowedAbsoluteTarget(string $targetUrl, ?Site $site): bool
    {
        $targetHost = strtolower(trim((string)parse_url($targetUrl, PHP_URL_HOST)));
        if ($targetHost === '') {
            return false;
        }

        if ($site instanceof Site) {
            $siteHost = strtolower(trim((string)parse_url((string)$site->getBase(), PHP_URL_HOST)));
            if ($siteHost !== '' && $targetHost === $siteHost) {
                return true;
            }

            foreach ($this->resolveAllowedHosts($site) as $allowedHost) {
                if ($targetHost === $allowedHost) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedHosts(Site $site): array
    {
        $allowedHosts = $site->getSettings()->get('lscache.probe.allowedHosts', null);
        if (!is_array($allowedHosts)) {
            $allowedHosts = $site->getConfiguration()['settings']['lscache']['probe']['allowedHosts'] ?? [];
        }
        if (!is_array($allowedHosts)) {
            return [];
        }

        $normalizedHosts = [];
        foreach ($allowedHosts as $host) {
            $host = strtolower(trim((string)$host));
            if ($host !== '') {
                $normalizedHosts[] = $host;
            }
        }

        return array_values(array_unique($normalizedHosts));
    }

    /**
     * @return array{
     *     state: string,
     *     title: string,
     *     message: string,
     *     details: string,
     *     iconIdentifier: string,
     *     statusClass: string,
     *     targetUrl: string,
     *     statusCode: null,
     *     headerName: null,
     *     headerValue: null
     * }
     */
    private function buildDisallowedTargetPayload(LanguageService $languageService, string $targetUrl): array
    {
        return [
            'state' => CacheProbeResult::STATE_NOT_CACHEABLE,
            'title' => $this->translate($languageService, 'probe.state.not-cacheable.title'),
            'message' => $this->translate($languageService, 'probe.error.disallowedTarget'),
            'details' => $this->translate($languageService, 'probe.details.disallowedTarget'),
            'iconIdentifier' => 'actions-exclamation-triangle',
            'statusClass' => 'warning',
            'targetUrl' => $targetUrl,
            'statusCode' => null,
            'headerName' => null,
            'headerValue' => null,
        ];
    }

    private function translate(LanguageService $languageService, string $key): string
    {
        return $languageService->sL('skom_lscache.messages:' . $key);
    }
}
