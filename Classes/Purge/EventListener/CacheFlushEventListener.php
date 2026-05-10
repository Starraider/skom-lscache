<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Purge\Audit\PurgeLogRepository;
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
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PurgeServiceInterface $purgeService,
        private readonly LoopbackDispatcher $loopbackDispatcher,
        private readonly PurgeLogRepository $purgeLogRepository,
    ) {}

    public function __invoke(CacheFlushEvent $event): void
    {
        $groups = $event->getGroups();

        // AC 5: only proceed when groups explicitly contains 'pages'
        if (!in_array('pages', $groups, true)) {
            return;
        }

        // AC 6: trigger_ref format is "cache-flush-event:<group-csv>"
        $triggerRef = 'cache-flush-event:' . implode(',', $groups);

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

            // AC 2: enqueue full-site purge and write audit log
            $this->purgeService->purgeFullSite($siteIdentifier, PurgeSource::CACHE_FLUSH_EVENT, $triggerRef);

            // AC 3: dispatch through loopback endpoint using audit requestId for correlation
            $requestId = $this->purgeLogRepository->findNewestRequestIdByTrigger(
                $siteIdentifier,
                PurgeSource::CACHE_FLUSH_EVENT,
                $triggerRef,
            );

            if ($requestId !== null) {
                $payload = new PurgePayload([], [], [$siteIdentifier]);
                $this->loopbackDispatcher->dispatch($payload, $site, $requestId);
            }
        }
    }
}
