<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

final readonly class CacheabilityClassifier
{
    public function __construct(
        private PageContentInventoryProvider $pageContentInventoryProvider,
        private Context $context,
    ) {}

    /**
     * @api
     */
    public function classify(ServerRequestInterface $request, ResponseInterface $response): CacheabilityDecision
    {
        if (ApplicationType::fromRequest($request)->isBackend()) {
            return CacheabilityDecision::bypass(BypassReason::BACKEND_CONTEXT);
        }

        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return CacheabilityDecision::bypass(BypassReason::REQUEST_METHOD);
        }

        $siteSettings = $this->resolveSiteSettings($request);
        if ($this->isFrontendUserLoggedIn($request) && $this->isBypassEnabled($siteSettings, 'lscache.bypass.loggedInFE', true)) {
            return CacheabilityDecision::bypass(BypassReason::FRONTEND_USER);
        }

        if ($response->hasHeader('Set-Cookie') && $this->isBypassEnabled($siteSettings, 'lscache.bypass.setCookie', true)) {
            return CacheabilityDecision::bypass(BypassReason::RESPONSE_SET_COOKIE);
        }

        $matchedPathRule = $this->firstMatchingPathRule($this->getBypassList($siteSettings, 'paths'), $request->getUri()->getPath());
        if ($matchedPathRule !== null) {
            return CacheabilityDecision::bypass(BypassReason::BYPASS_PATH, $matchedPathRule);
        }

        $inventory = $this->pageContentInventoryProvider->getForRequest($request);
        $matchedPluginRule = $this->firstMatchingRule($this->getBypassList($siteSettings, 'pluginSignatures'), $inventory->pluginSignatures);
        if ($matchedPluginRule !== null) {
            return CacheabilityDecision::bypass(BypassReason::BYPASS_PLUGIN_SIGNATURE, $matchedPluginRule);
        }

        $matchedCTypeRule = $this->firstMatchingRule($this->getBypassList($siteSettings, 'ctypes'), $inventory->ctypes);
        if ($matchedCTypeRule !== null) {
            return CacheabilityDecision::bypass(BypassReason::BYPASS_CTYPE, $matchedCTypeRule);
        }

        return CacheabilityDecision::cacheable();
    }

    private function resolveSiteSettings(ServerRequestInterface $request): SiteSettings
    {
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            return $site->getSettings();
        }

        return SiteSettings::createFromSettingsTree([]);
    }

    private function isFrontendUserLoggedIn(ServerRequestInterface $request): bool
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if ($frontendUser instanceof FrontendUserAuthentication) {
            return (int)($frontendUser->user['uid'] ?? 0) > 0;
        }

        return (bool)$this->context->getPropertyFromAspect('frontend.user', 'isLoggedIn', false);
    }

    private function isBypassEnabled(SiteSettings $siteSettings, string $key, bool $default): bool
    {
        $value = $siteSettings->get($key, null);
        if ($value !== null) {
            return (bool)$value;
        }

        $all = $siteSettings->getAll();
        $nested = $all['lscache']['bypass'] ?? [];
        $parts = explode('.', $key);
        $leaf = (string)end($parts);
        if (array_key_exists($leaf, $nested)) {
            return (bool)$nested[$leaf];
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private function getBypassList(SiteSettings $siteSettings, string $leaf): array
    {
        $value = $siteSettings->get('lscache.bypass.' . $leaf, null);
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        $all = $siteSettings->getAll();
        $nested = $all['lscache']['bypass'][$leaf] ?? [];
        if (is_array($nested)) {
            return array_values(array_map('strval', $nested));
        }

        return [];
    }

    /**
     * @param array<mixed> $rules
     * @param list<string> $candidates
     */
    private function firstMatchingRule(array $rules, array $candidates): ?string
    {
        foreach ($rules as $rule) {
            $rule = trim((string)$rule);
            if ($rule === '') {
                continue;
            }
            if (in_array($rule, $candidates, true)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $rules
     */
    private function firstMatchingPathRule(array $rules, string $path): ?string
    {
        foreach ($rules as $rule) {
            $rule = trim((string)$rule);
            if ($rule === '') {
                continue;
            }
            if (fnmatch($rule, $path)) {
                return $rule;
            }
        }

        return null;
    }
}
