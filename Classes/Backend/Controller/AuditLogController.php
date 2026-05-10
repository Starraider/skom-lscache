<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Skom\Lscache\Backend\Audit\AuditLogFilter;
use Skom\Lscache\Purge\Audit\PurgeLogDateRangeFilter;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogOutcomeFilter;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Audit\PurgeLogRow;
use Skom\Lscache\Purge\Audit\PurgeLogSiteFilter;
use Skom\Lscache\Purge\Audit\PurgeLogSourceFilter;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
final readonly class AuditLogController
{
    private const int EXPORT_CAP = 10000;

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
        $filter = $this->resolveFilter($request);

        $siteFilter = new PurgeLogSiteFilter($filter->getSiteIdentifiers());
        $sourceFilter = new PurgeLogSourceFilter($this->resolveSourceFilters($filter->getSourceValues()));
        $outcomeFilter = new PurgeLogOutcomeFilter($this->resolveOutcomeFilters($filter->getOutcomeValues()));
        $dateRangeFilter = new PurgeLogDateRangeFilter($filter->getFrom(), $filter->getTo());
        $hasInvalidFilters = $this->hasInvalidFilters($filter);

        $totalRows = $hasInvalidFilters ? 0 : $this->purgeLogRepository->countByFilters(
            $siteFilter,
            $sourceFilter,
            $outcomeFilter,
            $dateRangeFilter,
            $filter->getRequestId(),
        );
        $totalPages = max(1, (int)ceil($totalRows / $filter->getPerPage()));
        $currentPage = min($filter->getPage(), $totalPages);
        $currentOffset = max(0, ($currentPage - 1) * $filter->getPerPage());

        $rows = $hasInvalidFilters ? [] : $this->purgeLogRepository->findByFilters(
            $siteFilter,
            $sourceFilter,
            $outcomeFilter,
            $dateRangeFilter,
            $filter->getPerPage(),
            $currentOffset,
            $filter->getRequestId(),
        );

        $currentQuery = $filter->toQueryParameters();
        $currentQuery['page'] = $currentPage;

        $dashboardUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache');
        $auditLogBaseUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log');
        $auditLogUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log', $currentQuery);
        $pageStatusUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.page-status', [
            'site' => $filter->getSiteIdentifiers()[0] ?? 'unknown',
        ]);
        $manualPurgeUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.manual-purge', [
            'site' => $filter->getSiteIdentifiers()[0] ?? 'unknown',
        ]);
        $resetUrl = $auditLogBaseUrl;
        $exportUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log-export', $filter->toQueryParameters(false));

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate($languageService, 'dashboard.title'), $this->translate($languageService, 'auditLog.heading'));
        $this->pageRenderer->loadJavaScriptModule('@skom/lscache/backend-module.js');

        return $view
            ->assign('audit', [
                'dashboardUrl' => $dashboardUrl,
                'auditLogBaseUrl' => $auditLogBaseUrl,
                'auditLogUrl' => $auditLogUrl,
                'pageStatusUrl' => $pageStatusUrl,
                'manualPurgeUrl' => $manualPurgeUrl,
                'resetUrl' => $resetUrl,
                'exportUrl' => $exportUrl,
                'exportWillTruncate' => $totalRows > self::EXPORT_CAP,
                'siteOptions' => $this->buildSiteOptions($filter, $languageService),
                'sourceOptions' => $this->buildSourceOptions($filter, $languageService),
                'outcomeOptions' => $this->buildOutcomeOptions($filter, $languageService),
                'datePresetOptions' => $this->buildDatePresetOptions($filter, $languageService),
                'filters' => [
                    'siteIdentifiers' => $filter->getSiteIdentifiers(),
                    'sourceValues' => $filter->getSourceValues(),
                    'outcomeValues' => $filter->getOutcomeValues(),
                    'datePreset' => $filter->getDatePreset(),
                    'customFrom' => $filter->getCustomFrom() ?? '',
                    'customTo' => $filter->getCustomTo() ?? '',
                    'requestId' => $filter->getRequestId() ?? '',
                    'page' => $currentPage,
                    'perPage' => $filter->getPerPage(),
                    'perPageOptions' => $filter->getPerPageOptions(),
                ],
                'rows' => $this->mapRows($rows, $languageService, $auditLogUrl),
                'pagination' => $this->buildPagination($filter, $currentPage, $totalPages),
                'totalRows' => $totalRows,
                'isEmpty' => $rows === [],
            ])
            ->renderResponse('AuditLog/Index');
    }

    public function exportCsv(ServerRequestInterface $request): ResponseInterface
    {
        $filter = $this->resolveFilter($request);

        $siteFilter = new PurgeLogSiteFilter($filter->getSiteIdentifiers());
        $sourceFilter = new PurgeLogSourceFilter($this->resolveSourceFilters($filter->getSourceValues()));
        $outcomeFilter = new PurgeLogOutcomeFilter($this->resolveOutcomeFilters($filter->getOutcomeValues()));
        $dateRangeFilter = new PurgeLogDateRangeFilter($filter->getFrom(), $filter->getTo());
        $hasInvalidFilters = $this->hasInvalidFilters($filter);

        $totalRows = $hasInvalidFilters ? 0 : $this->purgeLogRepository->countByFilters(
            $siteFilter,
            $sourceFilter,
            $outcomeFilter,
            $dateRangeFilter,
            $filter->getRequestId(),
        );
        $isTruncated = $totalRows > self::EXPORT_CAP;
        $rows = $hasInvalidFilters ? [] : $this->purgeLogRepository->findForCsvExport(
            $siteFilter,
            $sourceFilter,
            $outcomeFilter,
            $dateRangeFilter,
            self::EXPORT_CAP,
            $filter->getRequestId(),
        );

        $csv = $this->buildCsv($rows, $isTruncated);
        $response = new Response('php://temp', 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="lscache-audit-log.csv"',
            'X-Skom-Lscache-Truncated' => $isTruncated ? '1' : '0',
        ]);
        $response->getBody()->write($csv);

        return $response;
    }

    private function resolveFilter(ServerRequestInterface $request): AuditLogFilter
    {
        $queryPayload = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $bodyPayload = is_array($parsedBody) ? $parsedBody : [];

        $payload = array_replace_recursive($queryPayload, $bodyPayload);

        return AuditLogFilter::fromPayload($payload);
    }

    private function hasInvalidFilters(AuditLogFilter $filter): bool
    {
        if ($filter->hasInvalidDateRange()) {
            return true;
        }

        if ($filter->hasInvalidRequestId()) {
            return true;
        }

        return count($filter->getSourceValues()) !== count($this->resolveSourceFilters($filter->getSourceValues()))
            || count($filter->getOutcomeValues()) !== count($this->resolveOutcomeFilters($filter->getOutcomeValues()));
    }

    /**
     * @param list<string> $sourceValues
     * @return list<PurgeSource>
     */
    private function resolveSourceFilters(array $sourceValues): array
    {
        $sources = [];
        foreach ($sourceValues as $sourceValue) {
            try {
                $source = PurgeSource::from($sourceValue);
            } catch (\ValueError) {
                continue;
            }

            $sources[$source->value] = $source;
        }

        return array_values($sources);
    }

    /**
     * @param list<string> $outcomeValues
     * @return list<PurgeLogOutcome>
     */
    private function resolveOutcomeFilters(array $outcomeValues): array
    {
        $outcomes = [];
        foreach ($outcomeValues as $outcomeValue) {
            try {
                $outcome = PurgeLogOutcome::from($outcomeValue);
            } catch (\ValueError) {
                continue;
            }

            $outcomes[$outcome->value] = $outcome;
        }

        return array_values($outcomes);
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function buildSiteOptions(AuditLogFilter $filter, LanguageService $languageService): array
    {
        $sites = $this->siteFinder->getAllSites();
        $siteIdentifiers = $this->purgeLogRepository->findDistinctSiteIdentifiers();
        foreach ($filter->getSiteIdentifiers() as $siteIdentifier) {
            if (!in_array($siteIdentifier, $siteIdentifiers, true)) {
                $siteIdentifiers[] = $siteIdentifier;
            }
        }

        sort($siteIdentifiers);

        $options = [];
        foreach ($siteIdentifiers as $siteIdentifier) {
            $site = $sites[$siteIdentifier] ?? null;
            $options[] = [
                'value' => $siteIdentifier,
                'label' => $this->resolveSiteLabel($siteIdentifier, $site, $languageService),
                'selected' => in_array($siteIdentifier, $filter->getSiteIdentifiers(), true),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, iconIdentifier: string, selected: bool}>
     */
    private function buildSourceOptions(AuditLogFilter $filter, LanguageService $languageService): array
    {
        $options = [];
        foreach (PurgeSource::cases() as $source) {
            $options[] = [
                'value' => $source->value,
                'label' => $this->translate($languageService, 'source.' . $source->value),
                'iconIdentifier' => $this->mapSourceIconIdentifier($source),
                'selected' => in_array($source->value, $filter->getSourceValues(), true),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, iconIdentifier: string, statusClass: string, selected: bool}>
     */
    private function buildOutcomeOptions(AuditLogFilter $filter, LanguageService $languageService): array
    {
        $options = [];
        foreach (PurgeLogOutcome::cases() as $outcome) {
            $options[] = [
                'value' => $outcome->value,
                'label' => $this->translate($languageService, 'outcome.' . $outcome->value),
                'iconIdentifier' => $this->mapOutcomeIconIdentifier($outcome),
                'statusClass' => $this->mapOutcomeStatusClass($outcome),
                'selected' => in_array($outcome->value, $filter->getOutcomeValues(), true),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function buildDatePresetOptions(AuditLogFilter $filter, LanguageService $languageService): array
    {
        $presets = ['lastHour', 'last24h', 'last7d', 'custom'];
        $options = [];
        foreach ($presets as $preset) {
            $options[] = [
                'value' => $preset,
                'label' => $this->translate($languageService, 'auditLog.filters.datePreset.' . $preset),
                'selected' => $preset === $filter->getDatePreset(),
            ];
        }

        return $options;
    }

    /**
     * @param list<PurgeLogRow> $rows
     * @return list<array{
     *     uid: int,
     *     requestId: string,
     *     absoluteTimestamp: string,
     *     relativeTimestamp: string,
     *     siteIdentifier: string,
     *     siteLabel: string,
     *     sourceValue: string,
     *     sourceLabel: string,
     *     sourceIconIdentifier: string,
     *     triggerRef: ?string,
     *     triggerEditUrl: ?string,
     *     tags: list<string>,
     *     tagCount: int,
     *     outcomeValue: string,
     *     outcomeLabel: string,
     *     outcomeIconIdentifier: string,
     *     outcomeStatusClass: string,
     *     latencyMs: int
     * }>
     */
    private function mapRows(array $rows, LanguageService $languageService, string $returnUrl): array
    {
        $sites = $this->siteFinder->getAllSites();
        $nowTimestamp = time();

        $mappedRows = [];
        foreach ($rows as $row) {
            $site = $sites[$row->siteIdentifier] ?? null;
            $mappedRows[] = [
                'uid' => $row->uid,
                'requestId' => $row->requestId,
                'absoluteTimestamp' => date('Y-m-d H:i:s', $row->crdate),
                'relativeTimestamp' => $this->formatRelativeTimestamp($row->crdate, $nowTimestamp, $languageService),
                'siteIdentifier' => $row->siteIdentifier,
                'siteLabel' => $this->resolveSiteLabel($row->siteIdentifier, $site, $languageService),
                'sourceValue' => $row->source->value,
                'sourceLabel' => $this->translate($languageService, 'source.' . $row->source->value),
                'sourceIconIdentifier' => $this->mapSourceIconIdentifier($row->source),
                'triggerRef' => $row->triggerRef,
                'triggerEditUrl' => $this->resolveTriggerEditUrl($row->triggerRef, $returnUrl),
                'tags' => $row->tags,
                'tagCount' => $row->tagCount,
                'outcomeValue' => $row->outcome->value,
                'outcomeLabel' => $this->translate($languageService, 'outcome.' . $row->outcome->value),
                'outcomeIconIdentifier' => $this->mapOutcomeIconIdentifier($row->outcome),
                'outcomeStatusClass' => $this->mapOutcomeStatusClass($row->outcome),
                'latencyMs' => $row->latencyMs,
            ];
        }

        return $mappedRows;
    }

    /**
     * @return array{
     *     currentPage: int,
     *     totalPages: int,
     *     hasPrev: bool,
     *     hasNext: bool,
     *     prevUrl: string,
     *     nextUrl: string,
     *     pageLinks: list<array{page: int, url: string, current: bool}>
     * }
     */
    private function buildPagination(AuditLogFilter $filter, int $currentPage, int $totalPages): array
    {
        $pageLinks = [];
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        if ($start > 1) {
            $pageLinks[] = [
                'page' => 1,
                'url' => $this->buildAuditLogRouteForPage($filter, 1),
                'current' => $currentPage === 1,
            ];
        }

        for ($page = $start; $page <= $end; $page++) {
            $pageLinks[] = [
                'page' => $page,
                'url' => $this->buildAuditLogRouteForPage($filter, $page),
                'current' => $page === $currentPage,
            ];
        }

        if ($end < $totalPages) {
            $pageLinks[] = [
                'page' => $totalPages,
                'url' => $this->buildAuditLogRouteForPage($filter, $totalPages),
                'current' => $currentPage === $totalPages,
            ];
        }

        return [
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'hasPrev' => $currentPage > 1,
            'hasNext' => $currentPage < $totalPages,
            'prevUrl' => $this->buildAuditLogRouteForPage($filter, max(1, $currentPage - 1)),
            'nextUrl' => $this->buildAuditLogRouteForPage($filter, min($totalPages, $currentPage + 1)),
            'pageLinks' => $pageLinks,
        ];
    }

    private function buildAuditLogRouteForPage(AuditLogFilter $filter, int $page): string
    {
        $query = $filter->toQueryParameters();
        $query['page'] = max(1, $page);

        return (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log', $query);
    }

    private function mapSourceIconIdentifier(PurgeSource $source): string
    {
        return match ($source) {
            PurgeSource::DATAHANDLER => 'actions-document-open',
            PurgeSource::CLI => 'actions-system-extension-configure',
            PurgeSource::MODULE => 'actions-menu-alternative',
            PurgeSource::API => 'actions-link',
            PurgeSource::CACHE_FLUSH_EVENT => 'actions-refresh',
        };
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

    private function resolveSiteLabel(string $siteIdentifier, ?Site $site, LanguageService $languageService): string
    {
        if ($siteIdentifier === 'unknown' && $site === null) {
            return $this->translate($languageService, 'site.unknown');
        }

        if ($site === null) {
            return $siteIdentifier;
        }

        $siteLabel = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
        if ($siteLabel === '') {
            $siteLabel = $site->getIdentifier();
        }

        return $siteLabel;
    }

    private function resolveTriggerEditUrl(?string $triggerRef, string $returnUrl): ?string
    {
        $triggerRef = trim((string)$triggerRef);
        if ($triggerRef === '') {
            return null;
        }

        if (!preg_match('/^(?<table>[a-zA-Z0-9_]+):(?<uid>[1-9][0-9]*)$/', $triggerRef, $matches)) {
            return null;
        }

        $table = (string)$matches['table'];
        $uid = (int)$matches['uid'];

        if (!is_array($GLOBALS['TCA'][$table] ?? null)) {
            return null;
        }

        try {
            return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [
                    $table => [
                        $uid => 'edit',
                    ],
                ],
                'returnUrl' => $returnUrl,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatRelativeTimestamp(int $timestamp, int $nowTimestamp, LanguageService $languageService): string
    {
        $delta = max(0, $nowTimestamp - $timestamp);

        if ($delta < 60) {
            return $this->translate($languageService, 'auditLog.time.relative.justNow');
        }

        if ($delta < 3600) {
            $minutes = max(1, (int)floor($delta / 60));

            return sprintf($this->translate($languageService, 'auditLog.time.relative.minutes'), $minutes);
        }

        if ($delta < 86400) {
            $hours = max(1, (int)floor($delta / 3600));

            return sprintf($this->translate($languageService, 'auditLog.time.relative.hours'), $hours);
        }

        $days = max(1, (int)floor($delta / 86400));

        return sprintf($this->translate($languageService, 'auditLog.time.relative.days'), $days);
    }

    /**
     * @param list<PurgeLogRow> $rows
     */
    private function buildCsv(array $rows, bool $isTruncated): string
    {
        $stream = fopen('php://temp', 'rb+');
        if (!is_resource($stream)) {
            return '';
        }

        if ($isTruncated) {
            fputcsv($stream, [
                'WARNING',
                sprintf('Export is limited to the newest %d rows for the selected filters.', self::EXPORT_CAP),
            ]);
        }

        fputcsv($stream, [
            'timestamp',
            'site_identifier',
            'source',
            'trigger_ref',
            'tags',
            'outcome',
            'latency_ms',
            'request_id',
        ]);

        foreach ($rows as $row) {
            fputcsv($stream, [
                date('Y-m-d H:i:s', $row->crdate),
                $row->siteIdentifier,
                $row->source->value,
                $row->triggerRef ?? '',
                implode('|', $row->tags),
                $row->outcome->value,
                $row->latencyMs,
                $row->requestId,
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    private function translate(LanguageService $languageService, string $key): string
    {
        return $languageService->sL('LLL:EXT:skom_lscache/Resources/Private/Language/locallang.xlf:' . $key);
    }
}
