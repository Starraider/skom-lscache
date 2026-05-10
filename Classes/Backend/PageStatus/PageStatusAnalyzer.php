<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\PageStatus;

use Doctrine\DBAL\ParameterType;
use Psr\EventDispatcher\EventDispatcherInterface;
use Skom\Lscache\Http\CacheabilityClassifierInterface;
use Skom\Lscache\Http\Event\CacheControlOverrideEvent;
use Skom\Lscache\Http\HeaderEmissionDecider;
use Skom\Lscache\Http\TagSchema;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class PageStatusAnalyzer
{
    public function __construct(
        private CacheabilityClassifierInterface $cacheabilityClassifier,
        private TagSchema $tagSchema,
        private HeaderEmissionDecider $headerEmissionDecider,
        private EventDispatcherInterface $eventDispatcher,
        private SiteFinder $siteFinder,
        private ConnectionPool $connectionPool,
    ) {}

    public function analyze(int $pageUid, string $selectedSiteIdentifier): PageStatusAnalysisResult
    {
        if ($pageUid <= 0) {
            throw PageStatusAnalysisException::invalidPageUid();
        }

        $pageRecord = $this->findPageRecord($pageUid);
        if ($pageRecord === null) {
            throw PageStatusAnalysisException::pageNotFound();
        }

        $pageSite = $this->resolveSiteForPage($pageUid);
        if (!$pageSite instanceof Site) {
            throw PageStatusAnalysisException::siteNotResolved();
        }

        $selectedSite = $this->resolveSiteByIdentifier($selectedSiteIdentifier);
        if ($selectedSite instanceof Site && $selectedSite->getIdentifier() !== $pageSite->getIdentifier()) {
            throw PageStatusAnalysisException::pageOutsideSelectedSite();
        }

        $site = $pageSite;

        $siteSettings = $site->getSettings();
        if (!$this->headerEmissionDecider->shouldEmitForSite($siteSettings)) {
            throw PageStatusAnalysisException::siteNotCacheEnabled();
        }

        $pageSlug = $this->normalizeSlug((string)($pageRecord['slug'] ?? ''));
        $probeTargetUrl = $this->resolveProbeTargetUrl($site, $pageSlug);
        $synthesizedRequest = $this->createSynthesizedRequest($site, $pageUid, $probeTargetUrl);
        $decision = $this->cacheabilityClassifier->classify($synthesizedRequest, new Response());

        $effectiveTtl = $this->resolveEffectiveTtl($synthesizedRequest, $siteSettings);
        $namespace = trim((string)$this->getSetting($siteSettings, 'lscache.tagNamespace', ''));
        if ($namespace === '') {
            $namespace = $site->getIdentifier();
        }

        $tagSet = $this->tagSchema->computeTagsForPage(
            $site->getIdentifier(),
            $namespace,
            $pageUid,
            [],
        );

        $pageTitle = trim((string)($pageRecord['title'] ?? ''));
        if ($pageTitle === '') {
            $pageTitle = 'Page #' . $pageUid;
        }

        return new PageStatusAnalysisResult(
            pageUid: $pageUid,
            pageTitle: $pageTitle,
            pageSlug: $pageSlug,
            siteIdentifier: $site->getIdentifier(),
            siteLabel: $this->resolveSiteLabel($site),
            tagNamespace: $namespace,
            cacheable: $decision->cacheable,
            reason: $decision->reason,
            matchedRule: $decision->matchedRule,
            effectiveTtl: $effectiveTtl,
            tags: $tagSet->tags,
            originalTagCount: $tagSet->originalCount,
            truncated: $tagSet->truncated,
            probeTargetUrl: $probeTargetUrl,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPageRecord(int $pageUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'title', 'slug', 'deleted')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row) || (int)($row['deleted'] ?? 0) === 1) {
            return null;
        }

        return $row;
    }

    private function resolveSiteForPage(int $pageUid): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveSiteByIdentifier(string $siteIdentifier): ?Site
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '' || $siteIdentifier === 'unknown') {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return null;
        }
    }

    private function createSynthesizedRequest(Site $site, int $pageUid, string $probeTargetUrl): ServerRequest
    {
        return (new ServerRequest(new Uri($probeTargetUrl), 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('routing', new PageArguments($pageUid, '0', []));
    }

    private function resolveEffectiveTtl(ServerRequest $request, SiteSettings $siteSettings): int
    {
        $defaultTtl = $this->getSetting($siteSettings, 'lscache.defaultTtl', 0);
        if (!is_int($defaultTtl) || $defaultTtl <= 0) {
            throw PageStatusAnalysisException::ttlNotConfigured();
        }

        try {
            $event = $this->eventDispatcher->dispatch(
                new CacheControlOverrideEvent($request, new Response(), $siteSettings, $defaultTtl),
            );
            if ($event instanceof CacheControlOverrideEvent && $event->getOverride() !== null) {
                return $event->getOverride()->ttl;
            }
        } catch (\Throwable) {
            // Keep default TTL when listeners fail to provide an override.
        }

        return $defaultTtl;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '/';
        }

        return '/' . ltrim($slug, '/');
    }

    private function resolveProbeTargetUrl(Site $site, string $pageSlug): string
    {
        $base = rtrim((string)$site->getBase(), '/');

        return $base . $pageSlug;
    }

    private function resolveSiteLabel(Site $site): string
    {
        $siteLabel = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));

        return $siteLabel !== '' ? $siteLabel : $site->getIdentifier();
    }

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
}
