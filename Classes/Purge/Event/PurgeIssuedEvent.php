<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Event;

use Skom\Lscache\Purge\Enum\PurgeSource;

/** @api */
final readonly class PurgeIssuedEvent
{
    /**
     * @param list<string> $tags
     * @param list<string> $urls
     */
    public function __construct(
        private ?string $site,
        private PurgeSource $source,
        private array $tags,
        private array $urls,
        private ?string $triggerRef,
        private string $requestId,
        private int $timestamp,
    ) {}

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function getSource(): PurgeSource
    {
        return $this->source;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @return list<string>
     */
    public function getUrls(): array
    {
        return $this->urls;
    }

    public function getTriggerRef(): ?string
    {
        return $this->triggerRef;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
