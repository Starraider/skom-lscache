<?php

declare(strict_types=1);

namespace Vendor\Project\EventListener;

use Psr\Log\LoggerInterface;
use Skom\Lscache\Purge\Event\PurgeIssuedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener(identifier: 'project-lscache-purge-observer')]
final readonly class PurgeIssuedEventListener
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(PurgeIssuedEvent $event): void
    {
        $this->logger->info('lscache-purge-observed', [
            'site' => $event->siteIdentifier,
            'source' => $event->source->value,
            'requestId' => $event->requestId,
        ]);
    }
}
