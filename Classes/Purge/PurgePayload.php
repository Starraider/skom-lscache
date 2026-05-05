<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

final readonly class PurgePayload
{
    /**
     * @param list<string> $tags
     * @param list<string> $urls
     * @param list<string> $fullSiteFlushes
     */
    public function __construct(
        private array $tags = [],
        private array $urls = [],
        private array $fullSiteFlushes = [],
    ) {}

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return $this->urls;
    }

    /**
     * @return list<string>
     */
    public function fullSiteFlushes(): array
    {
        return $this->fullSiteFlushes;
    }
}
