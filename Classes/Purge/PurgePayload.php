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

    /**
     * @return array{tags: list<string>, urls: list<string>, fullSiteFlushes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'tags' => $this->tags,
            'urls' => $this->urls,
            'fullSiteFlushes' => $this->fullSiteFlushes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tags = isset($data['tags']) && is_array($data['tags']) ? array_values(array_filter(array_map('strval', $data['tags']))) : [];
        $urls = isset($data['urls']) && is_array($data['urls']) ? array_values(array_filter(array_map('strval', $data['urls']))) : [];
        $flushes = isset($data['fullSiteFlushes']) && is_array($data['fullSiteFlushes']) ? array_values(array_filter(array_map('strval', $data['fullSiteFlushes']))) : [];

        return new self($tags, $urls, $flushes);
    }
}
