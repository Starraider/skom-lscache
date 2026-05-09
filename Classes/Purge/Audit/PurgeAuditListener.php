<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Skom\Lscache\Purge\Event\PurgeIssuedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Log\Channel;

/**
 * Persists purge intents before any response-time flush outcome is known.
 *
 * @internal
 */
#[AsEventListener(identifier: 'skom-lscache-purge-audit-listener')]
final readonly class PurgeAuditListener
{
    public function __construct(
        private PurgeLogRepository $purgeLogRepository,
        private PurgeAuditRequestContext $auditRequestContext,
        #[Channel('skom.lscache')]
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeIssuedEvent $event): void
    {
        $siteIdentifier = $this->resolveSiteIdentifier($event);

        try {
            $this->purgeLogRepository->insertEnqueuedEvent($event, $siteIdentifier);
            $this->auditRequestContext->recordRequestId($event->getRequestId());
        } catch (Exception) {
            $this->logger->warning('audit-persist-failed', [
                'site' => $siteIdentifier,
                'source' => $event->getSource()->value,
                'tagCount' => count($event->getTags()),
                'outcome' => 'audit-persist-failed',
                'latencyMs' => 0,
                'requestId' => $event->getRequestId(),
            ]);
        }
    }

    private function resolveSiteIdentifier(PurgeIssuedEvent $event): string
    {
        $siteIdentifier = $event->getSite();
        if ($siteIdentifier === null || trim($siteIdentifier) === '') {
            return 'unknown';
        }

        return $siteIdentifier;
    }
}
