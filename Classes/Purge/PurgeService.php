<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\Event\PurgeIssuedEvent;
use TYPO3\CMS\Core\Log\Channel;

final readonly class PurgeService implements PurgeServiceInterface
{
    public function __construct(
        private PurgeQueue $purgeQueue,
        private EventDispatcherInterface $eventDispatcher,
        #[Channel('skom.lscache')]
        private LoggerInterface $logger,
    ) {}

    public function purgeTags(array $tags, PurgeSource $source, ?string $triggerRef = null): void
    {
        $startedAt = microtime(true);
        $validatedTags = $this->validateTags($tags);
        $validatedTriggerRef = $this->validateTriggerRef($triggerRef);
        $requestId = $this->createRequestId();
        $timestamp = time();

        $this->purgeQueue->enqueueTags($validatedTags);
        try {
            $this->eventDispatcher->dispatch(new PurgeIssuedEvent(null, $source, $validatedTags, [], $validatedTriggerRef, $requestId, $timestamp));
        } finally {
            $this->logEnqueued(null, $source, count($validatedTags), $startedAt, $requestId);
        }
    }

    public function purgeUrl(string $url, PurgeSource $source, ?string $triggerRef = null): void
    {
        $startedAt = microtime(true);
        $validatedUrl = $this->validateAbsoluteUrl($url);
        $validatedTriggerRef = $this->validateTriggerRef($triggerRef);
        $requestId = $this->createRequestId();
        $timestamp = time();

        $this->purgeQueue->enqueueUrl($validatedUrl);
        try {
            $this->eventDispatcher->dispatch(new PurgeIssuedEvent(null, $source, [], [$validatedUrl], $validatedTriggerRef, $requestId, $timestamp));
        } finally {
            $this->logEnqueued(null, $source, 0, $startedAt, $requestId);
        }
    }

    public function purgeFullSite(string $siteIdentifier, PurgeSource $source, ?string $triggerRef = null): void
    {
        $startedAt = microtime(true);
        $validatedSiteIdentifier = $this->validateSiteIdentifier($siteIdentifier);
        $validatedTriggerRef = $this->validateTriggerRef($triggerRef);
        $requestId = $this->createRequestId();
        $timestamp = time();

        $this->purgeQueue->enqueueFullSiteFlush($validatedSiteIdentifier);
        try {
            $this->eventDispatcher->dispatch(
                new PurgeIssuedEvent($validatedSiteIdentifier, $source, [], [], $validatedTriggerRef, $requestId, $timestamp),
            );
        } finally {
            $this->logEnqueued($validatedSiteIdentifier, $source, 0, $startedAt, $requestId);
        }
    }

    public function purgeEverything(PurgeSource $source, ?string $triggerRef = null): void
    {
        $startedAt = microtime(true);
        $validatedTriggerRef = $this->validateTriggerRef($triggerRef);
        $requestId = $this->createRequestId();
        $timestamp = time();

        $this->purgeQueue->enqueueEverythingFlush();
        try {
            $this->eventDispatcher->dispatch(new PurgeIssuedEvent('*', $source, [], [], $validatedTriggerRef, $requestId, $timestamp));
        } finally {
            $this->logEnqueued('*', $source, 0, $startedAt, $requestId);
        }
    }

    /**
     * @param array<mixed> $tags
     * @return list<string>
     */
    private function validateTags(array $tags): array
    {
        if (!array_is_list($tags)) {
            throw new InvalidArgumentException('Tags must be a list of strings.', 1064099626);
        }

        $validatedTags = [];
        foreach ($tags as $index => $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException(sprintf('Tag at index %d must be a string.', $index), 9214114018);
            }
            if ($tag === '') {
                throw new InvalidArgumentException(sprintf('Tag at index %d must not be empty.', $index), 2529469201);
            }
            $validatedTags[] = $tag;
        }

        return $validatedTags;
    }

    private function validateAbsoluteUrl(string $url): string
    {
        if ($url === '') {
            throw new InvalidArgumentException('URL must not be empty.', 6498557876);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('URL must be valid.', 8494845186);
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] === '' || $parts['host'] === '') {
            throw new InvalidArgumentException('URL must be absolute.', 6732967133);
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL scheme must be http or https.', 9130296679);
        }

        return $url;
    }

    private function validateSiteIdentifier(string $siteIdentifier): string
    {
        if ($siteIdentifier === '') {
            throw new InvalidArgumentException('Site identifier must not be empty.', 3040747508);
        }

        return $siteIdentifier;
    }

    private function validateTriggerRef(?string $triggerRef): ?string
    {
        if ($triggerRef === null) {
            return null;
        }
        if ($triggerRef === '') {
            throw new InvalidArgumentException('Trigger reference must not be empty when provided.', 9568398564);
        }

        return $triggerRef;
    }

    private function logEnqueued(
        ?string $site,
        PurgeSource $source,
        int $tagCount,
        float $startedAt,
        string $requestId,
    ): void {
        $this->logger->info('purge-issued', [
            'site' => $site,
            'source' => $source->value,
            'tagCount' => $tagCount,
            'outcome' => 'enqueued',
            'latencyMs' => (int)round((microtime(true) - $startedAt) * 1000),
            'requestId' => $requestId,
        ]);
    }

    private function createRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
