<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Skom\Lscache\Backend\ViewModel\DashboardViewModel;
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
final readonly class DashboardController
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private UriBuilder $uriBuilder,
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PurgeLogRepository $purgeLogRepository,
        private SiteFinder $siteFinder,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->languageServiceFactory->createForBackendUser();
        $view = $this->moduleTemplateFactory->create($request);
        $sites = $this->siteFinder->getAllSites();
        $selectedSiteIdentifier = $this->resolveSelectedSiteIdentifier($request, $sites);
        $selectedSite = $this->resolveSelectedSite($selectedSiteIdentifier, $sites);
        $selectedSiteLabel = $this->resolveSiteLabel($selectedSiteIdentifier, $selectedSite, $languageService);
        $selectedSiteBaseUrl = $selectedSite instanceof Site
            ? rtrim((string)$selectedSite->getBase(), '/') . '/'
            : '/';

        $dashboardUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache');
        $auditLogUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log', [
            'site' => [$selectedSiteIdentifier],
        ]);
        $pageStatusUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.page-status', [
            'site' => $selectedSiteIdentifier,
        ]);
        $manualPurgeUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.manual-purge', [
            'site' => $selectedSiteIdentifier,
        ]);
        $probeEndpointUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.probe');

        $viewModel = new DashboardViewModel(
            selectedSiteIdentifier: $selectedSiteIdentifier,
            selectedSiteLabel: $selectedSiteLabel,
            selectedSiteBaseUrl: $selectedSiteBaseUrl,
            dashboardUrl: $dashboardUrl,
            probeEndpointUrl: $probeEndpointUrl,
            probeTargetUrl: $selectedSiteBaseUrl,
            siteOptions: $this->buildSiteOptions($sites, $selectedSiteIdentifier, $languageService),
            summaryCards: $this->buildSummaryCards($selectedSiteIdentifier, $languageService),
            siteBreakdown: $this->buildSiteBreakdown($languageService, $selectedSiteIdentifier),
            recentRows: $this->buildRecentRows($selectedSiteIdentifier, $languageService),
        );

        $this->pageRenderer->loadJavaScriptModule('@skom/lscache/backend-module.js');
        $view->setTitle($this->translate($languageService, 'dashboard.title'), $selectedSiteLabel);

        return $view
            ->assign('dashboard', $viewModel)
            ->assign('auditLogUrl', $auditLogUrl)
            ->assign('pageStatusUrl', $pageStatusUrl)
            ->assign('manualPurgeUrl', $manualPurgeUrl)
            ->renderResponse('Dashboard/Index');
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
     * @param array<string, Site> $sites
     * @return list<array{identifier: string, label: string, baseUrl: string, selected: bool}>
     */
    private function buildSiteOptions(array $sites, string $selectedSiteIdentifier, LanguageService $languageService): array
    {
        $siteOptions = [];
        foreach ($sites as $site) {
            $baseUrl = rtrim((string)$site->getBase(), '/') . '/';
            $siteOptions[] = [
                'identifier' => $site->getIdentifier(),
                'label' => $this->resolveSiteLabel($site->getIdentifier(), $site, $languageService),
                'baseUrl' => $baseUrl,
                'selected' => $site->getIdentifier() === $selectedSiteIdentifier,
            ];
        }

        return $siteOptions;
    }

    /**
     * @return list<array{key: string, label: string, description: string, value: int, iconIdentifier: string, statusClass: string}>
     */
    private function buildSummaryCards(string $selectedSiteIdentifier, LanguageService $languageService): array
    {
        $periods = [
            [
                'key' => 'last24h',
                'seconds' => 24 * 60 * 60,
                'iconIdentifier' => 'actions-clock',
                'statusClass' => 'primary',
            ],
            [
                'key' => 'last7d',
                'seconds' => 7 * 24 * 60 * 60,
                'iconIdentifier' => 'actions-calendar',
                'statusClass' => 'info',
            ],
            [
                'key' => 'last30d',
                'seconds' => 30 * 24 * 60 * 60,
                'iconIdentifier' => 'actions-history',
                'statusClass' => 'secondary',
            ],
        ];

        $cards = [];
        foreach ($periods as $period) {
            $cards[] = [
                'key' => $period['key'],
                'label' => $this->translate($languageService, 'dashboard.summary.' . $period['key'] . '.label'),
                'description' => $this->translate($languageService, 'dashboard.summary.' . $period['key'] . '.description'),
                'value' => $this->purgeLogRepository->countBySiteSince(
                    $selectedSiteIdentifier,
                    new DateTimeImmutable('-' . $period['seconds'] . ' seconds'),
                ),
                'iconIdentifier' => $period['iconIdentifier'],
                'statusClass' => $period['statusClass'],
            ];
        }

        return $cards;
    }

    /**
     * @return list<array{siteIdentifier: string, label: string, count: int, selected: bool}>
     */
    private function buildSiteBreakdown(LanguageService $languageService, string $selectedSiteIdentifier): array
    {
        $sites = $this->siteFinder->getAllSites();
        $siteBreakdown = [];
        foreach ($this->purgeLogRepository->findSiteBreakdown() as $row) {
            $siteIdentifier = trim($row['siteIdentifier']);
            $site = $sites[$siteIdentifier] ?? null;
            $siteBreakdown[] = [
                'siteIdentifier' => $siteIdentifier,
                'label' => $this->resolveSiteLabel($siteIdentifier, $site, $languageService),
                'count' => (int)$row['count'],
                'selected' => $siteIdentifier === $selectedSiteIdentifier,
            ];
        }

        return $siteBreakdown;
    }

    /**
     * @return list<array{
     *     uid: int,
     *     crdate: int,
     *     requestId: string,
     *     triggerRef: ?string,
     *     tagCount: int,
     *     source: string,
     *     sourceLabel: string,
     *     outcome: string,
     *     outcomeLabel: string,
     *     outcomeIconIdentifier: string,
     *     outcomeStatusClass: string
     * }>
     */
    private function buildRecentRows(string $selectedSiteIdentifier, LanguageService $languageService): array
    {
        $recentRows = [];
        foreach ($this->purgeLogRepository->findRecentBySite($selectedSiteIdentifier, 5, 0) as $row) {
            $recentRows[] = $this->mapRecentRow($row, $languageService);
        }

        return $recentRows;
    }

    /**
     * @return array{
     *     uid: int,
     *     crdate: int,
     *     requestId: string,
     *     triggerRef: ?string,
     *     tagCount: int,
     *     source: string,
     *     sourceLabel: string,
     *     outcome: string,
     *     outcomeLabel: string,
     *     outcomeIconIdentifier: string,
     *     outcomeStatusClass: string
     * }
     */
    private function mapRecentRow(PurgeLogRow $row, LanguageService $languageService): array
    {
        return [
            'uid' => $row->uid,
            'crdate' => $row->crdate,
            'requestId' => $row->requestId,
            'triggerRef' => $row->triggerRef,
            'tagCount' => $row->tagCount,
            'source' => $row->source->value,
            'sourceLabel' => $this->translate($languageService, 'source.' . $row->source->value),
            'outcome' => $row->outcome->value,
            'outcomeLabel' => $this->translate($languageService, 'outcome.' . $row->outcome->value),
            'outcomeIconIdentifier' => $this->mapOutcomeIconIdentifier($row->outcome),
            'outcomeStatusClass' => $this->mapOutcomeStatusClass($row->outcome),
        ];
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
        return $languageService->sL('LLL:EXT:skom_lscache/Resources/Private/Language/locallang.xlf:' . $key);
    }
}
