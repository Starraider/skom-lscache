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

/**
 * Evaluates TYPO3 frontend requests and responses against LSCache bypass rules.
 */
final readonly class CacheabilityClassifier implements CacheabilityClassifierInterface
{
    /**
     * @param PageContentInventoryProvider $pageContentInventoryProvider Provider used to inspect page content metadata.
     * @param Context $context TYPO3 context used as fallback for frontend user state.
     */
    public function __construct(
        private PageContentInventoryProvider $pageContentInventoryProvider,
        private Context $context,
    ) {}

    /**
     * Classifies a downstream response after TYPO3 has produced it.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with TYPO3 site, routing, and user attributes.
     * @param ResponseInterface $response Downstream response to inspect for privacy-affecting headers.
     *
     * @api
     */
    public function classify(ServerRequestInterface $request, ResponseInterface $response): CacheabilityDecision
    {
        try {
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
                return CacheabilityDecision::bypass(BypassReason::BYPASS_RULE_MATCH, $matchedPathRule);
            }

            $inventory = $this->pageContentInventoryProvider->getForRequest($request);
            $matchedPluginRule = $this->firstMatchingRule($this->getBypassList($siteSettings, 'pluginSignatures'), $inventory->pluginSignatures);
            if ($matchedPluginRule !== null) {
                return CacheabilityDecision::bypass(BypassReason::BYPASS_RULE_MATCH, $matchedPluginRule);
            }

            $matchedCTypeRule = $this->firstMatchingRule($this->getBypassList($siteSettings, 'ctypes'), $inventory->ctypes);
            if ($matchedCTypeRule !== null) {
                return CacheabilityDecision::bypass(BypassReason::BYPASS_RULE_MATCH, $matchedCTypeRule);
            }
        } catch (MissingRequestContext $exception) {
            return CacheabilityDecision::bypass(BypassReason::MISSING_REQUEST_CONTEXT);
        }

        return CacheabilityDecision::cacheable();
    }

    /**
     * Resolves site settings from the request's `site` attribute.
     *
     * @param ServerRequestInterface $request Current PSR-7 request.
     */
    private function resolveSiteSettings(ServerRequestInterface $request): SiteSettings
    {
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            return $site->getSettings();
        }

        throw MissingRequestContext::forAttribute('site');
    }

    /**
     * Determines whether a frontend user is logged in for the current request.
     *
     * @param ServerRequestInterface $request Current PSR-7 request, optionally carrying `frontend.user`.
     */
    private function isFrontendUserLoggedIn(ServerRequestInterface $request): bool
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if ($frontendUser instanceof FrontendUserAuthentication) {
            return (int)($frontendUser->user['uid'] ?? 0) > 0;
        }

        return (bool)$this->context->getPropertyFromAspect('frontend.user', 'isLoggedIn', false);
    }

    /**
     * Reads a boolean bypass setting from flat or nested site settings.
     *
     * @param SiteSettings $siteSettings Current TYPO3 site settings.
     * @param string $key Dotted bypass setting key such as `lscache.bypass.setCookie`.
     * @param bool $default Fallback value when the setting is absent.
     */
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
     * Reads a list-style bypass setting from flat or nested site settings.
     *
     * @param SiteSettings $siteSettings Current TYPO3 site settings.
     * @param string $leaf Leaf key below `lscache.bypass`, for example `paths`.
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
     * Returns the first exact bypass rule that appears in the candidate list.
     *
     * @param array<mixed> $rules Configured bypass rules.
     * @param list<string> $candidates Values detected from page inventory.
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
     * Returns the first configured path rule that matches the request path.
     *
     * @param array<mixed> $rules Configured path patterns using `fnmatch` syntax.
     * @param string $path Request path to compare against configured bypass patterns.
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
