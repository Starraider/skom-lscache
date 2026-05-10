<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Audit\PurgeAuditRequestContext;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\LoopbackDispatcher;
use Skom\Lscache\Purge\PurgePayload;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Opt-in mirror of TYPO3 core cache flush events to LSCache edge purge.
 *
 * Group mapping (documented per AC 5):
 * - Groups containing 'pages' → full-site purge for each opted-in site
 * - Groups ['system'] only   → NO purge (system cache has no edge relevance)
 * - empty / unspecified      → NO purge (explicit 'pages' membership required)
 *
 * Activated per site via lscache.enabled: true AND lscache.mirror.cacheFlushEvent: true.
 * Purge is dispatched through the loopback endpoint (AC 3) because CacheFlushEvent
 * does not fire during a frontend request with a drainable queue.
 */
final class CacheFlushEventListener
{
    private const string TRIGGER_REF_PREFIX = 'cache-flush-event:';
    private const int TRIGGER_REF_MAX_LENGTH = 255;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PurgeServiceInterface $purgeService,
        private readonly LoopbackDispatcher $loopbackDispatcher,
        private readonly PurgeAuditRequestContext $purgeAuditRequestContext,
    ) {}

    public function __invoke(CacheFlushEvent $event): void
    {
        $groups = $event->getGroups();

        // AC 5: only proceed when groups explicitly contains 'pages'
        if (!in_array('pages', $groups, true)) {
            return;
        }

        // AC 6: trigger_ref format starts with "cache-flush-event:<group-csv>"
        $triggerRef = $this->buildTriggerRef($groups);

        foreach ($this->siteFinder->getAllSites() as $site) {
            $settings = $site->getSettings();

            $enabled = (bool)$settings->get('lscache.enabled', false);
            $mirrorEnabled = (bool)$settings->get('lscache.mirror.cacheFlushEvent', false);
            $secret = (string)$settings->get('lscache.loopback.secret', '');

            // AC 1 / AC 2: skip sites where mirror is off, lscache is disabled, or secret is absent
            if (!$enabled || !$mirrorEnabled || $secret === '') {
                continue;
            }

            $siteIdentifier = $site->getIdentifier();
            $knownRequestIds = array_fill_keys($this->purgeAuditRequestContext->peekRequestIds(), true);

            // AC 2: enqueue full-site purge and write audit log
            $this->purgeService->purgeFullSite($siteIdentifier, PurgeSource::CACHE_FLUSH_EVENT, $triggerRef);

            // AC 3: use the newly persisted requestId from request-scoped audit context
            $requestId = $this->resolveNewRequestId($knownRequestIds);

            if ($requestId !== null) {
                $payload = new PurgePayload([], [], [$siteIdentifier]);
                $this->loopbackDispatcher->dispatch($payload, $site, $requestId);
            }
        }
    }

    /**
     * @param list<string> $groups
     */
    private function buildTriggerRef(array $groups): string
    {
        $groupCsv = implode(',', $groups);
        $candidate = self::TRIGGER_REF_PREFIX . $groupCsv;
        if (strlen($candidate) <= self::TRIGGER_REF_MAX_LENGTH) {
            return $candidate;
        }

        $hash = substr(hash('sha256', $groupCsv), 0, 12);
        $suffix = '#' . $hash;
        $maxGroupCsvLength = self::TRIGGER_REF_MAX_LENGTH - strlen(self::TRIGGER_REF_PREFIX) - strlen($suffix);
        if ($maxGroupCsvLength <= 0) {
            return self::TRIGGER_REF_PREFIX . $suffix;
        }

        $truncatedGroupCsv = rtrim(substr($groupCsv, 0, $maxGroupCsvLength), ',');

        return self::TRIGGER_REF_PREFIX . $truncatedGroupCsv . $suffix;
    }

    /**
     * @param array<string, true> $knownRequestIds
     */
    private function resolveNewRequestId(array $knownRequestIds): ?string
    {
        foreach (array_reverse($this->purgeAuditRequestContext->peekRequestIds()) as $requestId) {
            if (!isset($knownRequestIds[$requestId])) {
                return $requestId;
            }
        }

        return null;
    }
}
