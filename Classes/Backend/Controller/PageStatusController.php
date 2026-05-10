<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Skom\Lscache\Backend\PageStatus\PageStatusAnalysisException;
use Skom\Lscache\Backend\PageStatus\PageStatusAnalyzer;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Audit\PurgeLogRow;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
final readonly class PageStatusController
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private UriBuilder $uriBuilder,
        private ModuleTemplateFactory $moduleTemplateFactory,
        private SiteFinder $siteFinder,
        private LanguageServiceFactory $languageServiceFactory,
        private PageStatusAnalyzer $pageStatusAnalyzer,
        private PurgeLogRepository $purgeLogRepository,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->languageServiceFactory->createForBackendUser();
        $view = $this->moduleTemplateFactory->create($request);
        $sites = $this->siteFinder->getAllSites();

        $selectedSiteIdentifier = $this->resolveSelectedSiteIdentifier($request, $sites);
        $selectedSite = $this->resolveSelectedSite($selectedSiteIdentifier, $sites);
        $selectedSiteLabel = $this->resolveSiteLabel($selectedSiteIdentifier, $selectedSite, $languageService);
        $pageUidState = $this->resolveRequestedPageUid($request);
        $pageUid = $pageUidState['pageUid'];

        $dashboardUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache', [
            'site' => $selectedSiteIdentifier,
        ]);
        $auditLogUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log', [
            'site' => [$selectedSiteIdentifier],
        ]);
        $pageStatusUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.page-status', array_filter([
            'site' => $selectedSiteIdentifier,
            'pageUid' => $pageUid,
        ]));
        $manualPurgeUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.manual-purge', [
            'site' => $selectedSiteIdentifier,
        ]);
        $probeEndpointUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.probe');

        $errorMessage = $pageUidState['invalid']
            ? $this->translate($languageService, PageStatusAnalysisException::invalidPageUid()->getTranslationKey())
            : null;
        $analysisResult = null;
        $recentRows = [];

        if ($pageUid !== null && $errorMessage === null) {
            try {
                $analysisResult = $this->pageStatusAnalyzer->analyze($pageUid, $selectedSiteIdentifier);
                $recentRows = $this->mapRecentRows(
                    $this->purgeLogRepository->findRecentBySiteTouchingAnyTag(
                        $analysisResult->siteIdentifier,
                        $analysisResult->tags,
                        5,
                    ),
                    $languageService,
                );
            } catch (PageStatusAnalysisException $exception) {
                $errorMessage = $this->translate($languageService, $exception->getTranslationKey());
            } catch (\Throwable) {
                $errorMessage = $this->translate($languageService, 'pageStatus.error.generic');
            }
        }

        $this->pageRenderer->loadJavaScriptModule('@skom/lscache/backend-module.js');
        $view->setTitle($this->translate($languageService, 'dashboard.title'), $this->translate($languageService, 'pageStatus.heading'));

        return $view
            ->assign('pageStatus', [
                'dashboardUrl' => $dashboardUrl,
                'auditLogUrl' => $auditLogUrl,
                'pageStatusUrl' => $pageStatusUrl,
                'manualPurgeUrl' => $manualPurgeUrl,
                'probeEndpointUrl' => $probeEndpointUrl,
                'selectedSiteIdentifier' => $selectedSiteIdentifier,
                'selectedSiteLabel' => $selectedSiteLabel,
                'selectedSiteBaseUrl' => $selectedSite instanceof Site ? rtrim((string)$selectedSite->getBase(), '/') . '/' : '/',
                'siteOptions' => $this->buildSiteOptions($sites, $selectedSiteIdentifier, $languageService),
                'requestedPageUid' => $pageUid,
                'result' => $analysisResult === null ? null : [
                    'pageUid' => $analysisResult->pageUid,
                    'pageTitle' => $analysisResult->pageTitle,
                    'pageSlug' => $analysisResult->pageSlug,
                    'siteIdentifier' => $analysisResult->siteIdentifier,
                    'siteLabel' => $analysisResult->siteLabel,
                    'tagNamespace' => $analysisResult->tagNamespace,
                    'cacheable' => $analysisResult->cacheable,
                    'reason' => $analysisResult->reason->value,
                    'reasonLabel' => $this->translate($languageService, 'pageStatus.reason.' . $analysisResult->reason->value),
                    'matchedRule' => $analysisResult->matchedRule,
                    'effectiveTtl' => $analysisResult->effectiveTtl,
                    'tags' => $analysisResult->tags,
                    'tagCount' => count($analysisResult->tags),
                    'originalTagCount' => $analysisResult->originalTagCount,
                    'truncated' => $analysisResult->truncated,
                    'probeTargetUrl' => $analysisResult->probeTargetUrl,
                ],
                'recentRows' => $recentRows,
                'error' => $errorMessage,
            ])
            ->renderResponse('PageStatus/Index');
    }

    /**
     * @param array<string, Site> $sites
     */
    private function resolveSelectedSiteIdentifier(ServerRequestInterface $request, array $sites): string
    {
        $requestedSite = trim((string)($request->getQueryParams()['site'] ?? ''));
        if ($requestedSite !== '' && isset($sites[$requestedSite])) {
            return $requestedSite;
        }

        if ($requestedSite !== '' && $requestedSite === 'unknown') {
            return 'unknown';
        }

        $requestSite = $request->getAttribute('site');
        if ($requestSite instanceof Site && isset($sites[$requestSite->getIdentifier()])) {
            return $requestSite->getIdentifier();
        }

        $siteIdentifiers = array_keys($sites);
        if ($siteIdentifiers !== []) {
            return (string)reset($siteIdentifiers);
        }

        return 'unknown';
    }

    /**
     * @param array<string, Site> $sites
     */
    private function resolveSelectedSite(string $selectedSiteIdentifier, array $sites): ?Site
    {
        return $sites[$selectedSiteIdentifier] ?? null;
    }

    private function resolveSiteLabel(string $selectedSiteIdentifier, ?Site $site, LanguageService $languageService): string
    {
        if ($selectedSiteIdentifier === 'unknown' && $site === null) {
            return $this->translate($languageService, 'site.unknown');
        }

        if ($site === null) {
            return $selectedSiteIdentifier;
        }

        $siteLabel = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
        if ($siteLabel === '') {
            $siteLabel = $site->getIdentifier();
        }

        return $siteLabel;
    }

    /**
     * @return array{pageUid: ?int, invalid: bool}
     */
    private function resolveRequestedPageUid(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();

        if (array_key_exists('pageUid', $queryParams)) {
            return $this->parsePositiveInt($queryParams['pageUid']);
        }

        if (array_key_exists('id', $queryParams)) {
            return $this->parsePositiveInt($queryParams['id']);
        }

        return [
            'pageUid' => null,
            'invalid' => false,
        ];
    }

    /**
     * @param mixed $value
     * @return array{pageUid: ?int, invalid: bool}
     */
    private function parsePositiveInt(mixed $value): array
    {
        if (is_int($value)) {
            return [
                'pageUid' => $value > 0 ? $value : null,
                'invalid' => $value <= 0,
            ];
        }

        if (!is_string($value) && !is_float($value) && !is_bool($value)) {
            return [
                'pageUid' => null,
                'invalid' => true,
            ];
        }

        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return [
                'pageUid' => null,
                'invalid' => true,
            ];
        }

        if (filter_var($stringValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return [
                'pageUid' => null,
                'invalid' => true,
            ];
        }

        return [
            'pageUid' => (int)$stringValue,
            'invalid' => false,
        ];
    }

    /**
     * @param array<string, Site> $sites
     * @return list<array{identifier: string, label: string, selected: bool}>
     */
    private function buildSiteOptions(array $sites, string $selectedSiteIdentifier, LanguageService $languageService): array
    {
        $siteOptions = [];
        foreach ($sites as $site) {
            $siteOptions[] = [
                'identifier' => $site->getIdentifier(),
                'label' => $this->resolveSiteLabel($site->getIdentifier(), $site, $languageService),
                'selected' => $site->getIdentifier() === $selectedSiteIdentifier,
            ];
        }

        return $siteOptions;
    }

    /**
     * @param list<PurgeLogRow> $rows
     * @return list<array{
     *     requestId: string,
     *     crdate: int,
     *     sourceLabel: string,
     *     outcomeLabel: string,
     *     outcomeIconIdentifier: string,
     *     outcomeStatusClass: string,
     *     tagCount: int
     * }>
     */
    private function mapRecentRows(array $rows, LanguageService $languageService): array
    {
        $mappedRows = [];
        foreach ($rows as $row) {
            $mappedRows[] = [
                'requestId' => $row->requestId,
                'crdate' => $row->crdate,
                'sourceLabel' => $this->translate($languageService, 'source.' . $row->source->value),
                'outcomeLabel' => $this->translate($languageService, 'outcome.' . $row->outcome->value),
                'outcomeIconIdentifier' => $this->mapOutcomeIconIdentifier($row->outcome),
                'outcomeStatusClass' => $this->mapOutcomeStatusClass($row->outcome),
                'tagCount' => $row->tagCount,
            ];
        }

        return $mappedRows;
    }

    private function mapOutcomeIconIdentifier(PurgeLogOutcome $outcome): string
    {
        return match ($outcome) {
            PurgeLogOutcome::ENQUEUED => 'actions-clock',
            PurgeLogOutcome::FLUSHED => 'actions-check-circle',
            PurgeLogOutcome::TRUNCATED => 'actions-exclamation-triangle',
            PurgeLogOutcome::FAILED => 'actions-close',
            PurgeLogOutcome::SKIPPED => 'actions-exclamation',
        };
    }

    private function mapOutcomeStatusClass(PurgeLogOutcome $outcome): string
    {
        return match ($outcome) {
            PurgeLogOutcome::ENQUEUED => 'secondary',
            PurgeLogOutcome::FLUSHED => 'success',
            PurgeLogOutcome::TRUNCATED => 'warning',
            PurgeLogOutcome::FAILED => 'danger',
            PurgeLogOutcome::SKIPPED => 'info',
        };
    }

    private function translate(LanguageService $languageService, string $key): string
    {
        return $languageService->sL('skom_lscache.messages:' . $key);
    }
}
