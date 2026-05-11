<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\LoopbackDispatcher;
use Skom\Lscache\Purge\PurgePayload;
use Skom\Lscache\Purge\PurgeServiceInterface;
use Skom\Lscache\Purge\SiteTagNamespaceResolver;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
final readonly class ManualPurgeController
{
    private const string FORM_NAME = 'web_SkomLscacheCache.manual-purge';
    private const string FORM_ACTION = 'execute';
    private const string BACKEND_USER_ATTRIBUTE = 'backend.user';

    public function __construct(
        private PageRenderer $pageRenderer,
        private UriBuilder $uriBuilder,
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ConnectionPool $connectionPool,
        private SiteFinder $siteFinder,
        private FormProtectionFactory $formProtectionFactory,
        private LanguageServiceFactory $languageServiceFactory,
        private PurgeServiceInterface $purgeService,
        private SiteTagNamespaceResolver $siteTagNamespaceResolver,
        private PurgeLogRepository $purgeLogRepository,
        private LoopbackDispatcher $loopbackDispatcher,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $languageService = $this->languageServiceFactory->createForBackendUser();
        $view = $this->moduleTemplateFactory->create($request);
        $sites = $this->siteFinder->getAllSites();

        $selectedSiteIdentifier = $this->resolveSelectedSiteIdentifier($request, $sites);
        $selectedSite = $this->resolveSelectedSite($selectedSiteIdentifier, $sites);
        $selectedSiteLabel = $this->resolveSiteLabel($selectedSiteIdentifier, $selectedSite, $languageService);

        $dashboardUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache', [
            'site' => $selectedSiteIdentifier,
        ]);
        $auditLogUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log', [
            'site' => [$selectedSiteIdentifier],
        ]);
        $pageStatusUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.page-status', [
            'site' => $selectedSiteIdentifier,
        ]);
        $manualPurgeUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.manual-purge', [
            'site' => $selectedSiteIdentifier,
        ]);
        $manualPurgeSubmitUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.manual-purge-execute');

        $formProtection = $this->formProtectionFactory->createForType('backend');
        $formToken = $formProtection->generateToken(self::FORM_NAME, self::FORM_ACTION);

        $this->pageRenderer->loadJavaScriptModule('@skom/lscache/backend-module.js');
        $view->setTitle($this->translate($languageService, 'dashboard.title'), $this->translate($languageService, 'manualPurge.heading'));

        return $view
            ->assign('manualPurge', [
                'dashboardUrl' => $dashboardUrl,
                'auditLogUrl' => $auditLogUrl,
                'pageStatusUrl' => $pageStatusUrl,
                'manualPurgeUrl' => $manualPurgeUrl,
                'manualPurgeSubmitUrl' => $manualPurgeSubmitUrl,
                'selectedSiteIdentifier' => $selectedSiteIdentifier,
                'selectedSiteLabel' => $selectedSiteLabel,
                'selectedSiteBaseUrl' => $selectedSite instanceof Site ? rtrim((string)$selectedSite->getBase(), '/') . '/' : '/',
                'siteOptions' => $this->buildSiteOptions($sites, $selectedSiteIdentifier, $languageService),
                'recentTagSuggestions' => $this->purgeLogRepository->findRecentTagsBySite($selectedSiteIdentifier, 16),
                'formToken' => $formToken,
            ])
            ->renderResponse('ManualPurge/Index');
    }

    public function submitRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $payload = is_array($parsedBody) ? $parsedBody : [];

        $siteIdentifier = trim((string)($payload['site'] ?? ''));
        $site = $this->resolveSiteByIdentifier($siteIdentifier);
        if (!$site instanceof Site) {
            return $this->createBadRequestResponse();
        }

        $requestToken = trim((string)($payload['__RequestToken'] ?? ''));
        $formProtection = $this->formProtectionFactory->createForType('backend');
        if ($requestToken === '' || !$formProtection->validateToken($requestToken, self::FORM_NAME, self::FORM_ACTION)) {
            return $this->createBadRequestResponse();
        }

        $scope = trim((string)($payload['scope'] ?? ''));
        $backendUsername = $this->resolveBackendUsername($request);

        $requestId = null;
        $scopeLabel = '';
        $purgePayload = null;

        if ($scope === 'page') {
            $pageUidState = $this->parsePositiveInt($payload['pageUid'] ?? null);
            $pageUid = $pageUidState['value'];
            if ($pageUidState['invalid'] || $pageUid === null || !$this->pageBelongsToSite($pageUid, $siteIdentifier)) {
                return $this->createBadRequestResponse();
            }

            $namespace = $this->siteTagNamespaceResolver->resolveForSite($siteIdentifier);
            $pageTag = $namespace . '_page_' . $pageUid;
            $triggerRef = sprintf('module:manual-purge:page:%d:be_user:%s', $pageUid, $backendUsername);
            $this->purgeService->purgeTags([$pageTag], PurgeSource::MODULE, $triggerRef, $siteIdentifier);
            $requestId = $this->purgeLogRepository->findNewestRequestIdByTrigger($siteIdentifier, PurgeSource::MODULE, $triggerRef);
            $purgePayload = new PurgePayload([$pageTag], [], []);
            $scopeLabel = 'page:' . $pageUid;
        } elseif ($scope === 'tags') {
            $tags = $this->parseTags((string)($payload['tags'] ?? ''));
            if ($tags === []) {
                return $this->createBadRequestResponse();
            }

            $namespace = $this->siteTagNamespaceResolver->resolveForSite($siteIdentifier);
            $expectedPrefix = $namespace . '_';
            foreach ($tags as $tag) {
                if (!str_starts_with($tag, $expectedPrefix)) {
                    return $this->createBadRequestResponse();
                }
            }

            $triggerRef = sprintf('module:manual-purge:tags:be_user:%s', $backendUsername);
            $this->purgeService->purgeTags($tags, PurgeSource::MODULE, $triggerRef, $siteIdentifier);
            $requestId = $this->purgeLogRepository->findNewestRequestIdByTrigger($siteIdentifier, PurgeSource::MODULE, $triggerRef);
            $purgePayload = new PurgePayload($tags, [], []);
            $scopeLabel = 'tags:' . count($tags);
        } elseif ($scope === 'site') {
            $confirmation = trim((string)($payload['confirmSitePurge'] ?? ''));
            if (!in_array($confirmation, ['1', 'true', 'on', 'yes'], true)) {
                return $this->createBadRequestResponse();
            }

            $triggerRef = sprintf('module:manual-purge:site:be_user:%s', $backendUsername);
            $this->purgeService->purgeFullSite($siteIdentifier, PurgeSource::MODULE, $triggerRef);
            $requestId = $this->purgeLogRepository->findNewestRequestIdByTrigger($siteIdentifier, PurgeSource::MODULE, $triggerRef);
            $purgePayload = new PurgePayload([], [], [$siteIdentifier]);
            $scopeLabel = 'site';
        } else {
            return $this->createBadRequestResponse();
        }

        if ($requestId === null) {
            return $this->createBadRequestResponse();
        }

        $startedAt = microtime(true);
        $dispatchSucceeded = $this->loopbackDispatcher->dispatch($purgePayload, $site, $requestId);
        if (!$dispatchSucceeded) {
            $this->purgeLogRepository->markFailedByRequestIds([$requestId], (int)round((microtime(true) - $startedAt) * 1000));
        }

        $languageService = $this->languageServiceFactory->createForBackendUser();
        $auditDeepLink = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.audit-log', [
            'site' => [$siteIdentifier],
            'requestId' => $requestId,
        ]);
        $manualPurgeUrl = (string)$this->uriBuilder->buildUriFromRoute('web_SkomLscacheCache.manual-purge', [
            'site' => $siteIdentifier,
        ]);

        $view = $this->moduleTemplateFactory->create($request);
        if ($dispatchSucceeded) {
            $view->addFlashMessage(
                sprintf(
                    $this->translate($languageService, 'manualPurge.flash.success.body'),
                    $siteIdentifier,
                    $scopeLabel,
                    $requestId,
                    $auditDeepLink,
                ),
                $this->translate($languageService, 'manualPurge.flash.success.title'),
                ContextualFeedbackSeverity::OK,
                true,
            );
        } else {
            $view->addFlashMessage(
                sprintf(
                    $this->translate($languageService, 'manualPurge.flash.failure.body'),
                    $siteIdentifier,
                    $scopeLabel,
                    $requestId,
                    $auditDeepLink,
                ),
                $this->translate($languageService, 'manualPurge.flash.failure.title'),
                ContextualFeedbackSeverity::ERROR,
                true,
            );
        }

        return new Response('php://temp', 303, [
            'Location' => $manualPurgeUrl,
        ]);
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

    private function pageBelongsToSite(int $pageUid, string $siteIdentifier): bool
    {
        $site = $this->resolveSiteByIdentifier($siteIdentifier);
        if (!$site instanceof Site || $pageUid <= 0) {
            return false;
        }

        $rootPageId = $site->getRootPageId();
        $currentPageUid = $pageUid;
        for ($hop = 0; $hop < 100; $hop++) {
            if ($currentPageUid === $rootPageId) {
                return true;
            }

            $pageRecord = $this->fetchPageRecord($currentPageUid);
            if ($pageRecord === null) {
                return false;
            }

            $parentPageUid = (int)($pageRecord['pid'] ?? 0);
            if ($parentPageUid <= 0 || $parentPageUid === $currentPageUid) {
                return false;
            }

            $currentPageUid = $parentPageUid;
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPageRecord(int $pageUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'pid', 'deleted')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row) || (int)($row['deleted'] ?? 0) === 1) {
            return null;
        }

        return $row;
    }

    /**
     * @param mixed $value
     * @return array{value: ?int, invalid: bool}
     */
    private function parsePositiveInt(mixed $value): array
    {
        if (is_int($value)) {
            return [
                'value' => $value > 0 ? $value : null,
                'invalid' => $value <= 0,
            ];
        }

        if (!is_string($value) && !is_float($value) && !is_bool($value)) {
            return [
                'value' => null,
                'invalid' => true,
            ];
        }

        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return [
                'value' => null,
                'invalid' => true,
            ];
        }

        if (filter_var($stringValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return [
                'value' => null,
                'invalid' => true,
            ];
        }

        return [
            'value' => (int)$stringValue,
            'invalid' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseTags(string $rawTags): array
    {
        $tokens = preg_split('/[\s,]+/', trim($rawTags)) ?: [];

        $normalized = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $normalized[$token] = true;
        }

        return array_keys($normalized);
    }

    private function resolveBackendUsername(ServerRequestInterface $request): string
    {
        $backendUser = $request->getAttribute(self::BACKEND_USER_ATTRIBUTE);
        if (!$backendUser instanceof BackendUserAuthentication && ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication) {
            $backendUser = $GLOBALS['BE_USER'];
        }

        if ($backendUser instanceof BackendUserAuthentication) {
            $username = trim((string)($backendUser->user['username'] ?? ''));
            if ($username !== '') {
                return $username;
            }
        }

        return 'unknown';
    }

    private function createBadRequestResponse(int $statusCode = 400): ResponseInterface
    {
        return new Response('php://temp', $statusCode);
    }

    private function translate(LanguageService $languageService, string $key): string
    {
        return $languageService->sL('skom_lscache.messages:' . $key);
    }
}
