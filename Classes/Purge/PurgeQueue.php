<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

final class PurgeQueue
{
    /**
     * @var array<string, string>
     */
    private array $fullSiteFlushNamespaces = [];

    /**
     * @var list<string>
     */
    private array $tags = [];

    /**
     * @var list<string>
     */
    private array $urls = [];

    /**
     * @var list<string>
     */
    private array $fullSiteFlushes = [];

    public function __construct(
        private readonly TagNamespaceResolverInterface $tagNamespaceResolver,
    ) {}

    /**
     * @param list<string> $tags
     */
    public function enqueueTags(array $tags): void
    {
        if ($this->hasEverythingFlush()) {
            return;
        }

        foreach ($tags as $tag) {
            if ($tag === '') {
                continue;
            }

            if ($this->tagIsCoveredByAnyFullSiteFlush($tag)) {
                continue;
            }

            $this->tags[] = $tag;
        }

        $this->tags = array_values(array_unique($this->tags));
    }

    public function enqueueFullSiteFlush(string $siteIdentifier): void
    {
        if ($siteIdentifier === '') {
            return;
        }
        if ($this->hasEverythingFlush()) {
            return;
        }

        $tagNamespace = $this->tagNamespaceResolver->resolveForSite($siteIdentifier);

        $this->fullSiteFlushes[] = $siteIdentifier;
        $this->fullSiteFlushes = array_values(array_unique($this->fullSiteFlushes));
        $this->fullSiteFlushNamespaces[$siteIdentifier] = $tagNamespace;
        $this->tags = array_values(array_filter(
            $this->tags,
            fn (string $tag): bool => !$this->tagMatchesNamespace($tag, $tagNamespace),
        ));
    }

    public function enqueueEverythingFlush(): void
    {
        $this->tags = [];
        $this->urls = [];
        $this->fullSiteFlushes = ['*'];
        $this->fullSiteFlushNamespaces = ['*' => '*'];
    }

    public function enqueueUrl(string $absoluteUrl): void
    {
        if ($this->hasEverythingFlush()) {
            return;
        }

        if (!$this->isAbsoluteUrl($absoluteUrl)) {
            return;
        }

        $this->urls[] = $absoluteUrl;
        $this->urls = array_values(array_unique($this->urls));
    }

    public function drain(): PurgePayload
    {
        $payload = new PurgePayload(
            tags: $this->tags,
            urls: $this->urls,
            fullSiteFlushes: $this->fullSiteFlushes,
        );

        $this->tags = [];
        $this->urls = [];
        $this->fullSiteFlushes = [];
        $this->fullSiteFlushNamespaces = [];

        return $payload;
    }

    private function tagIsCoveredByAnyFullSiteFlush(string $tag): bool
    {
        if ($this->hasEverythingFlush()) {
            return true;
        }

        foreach ($this->fullSiteFlushNamespaces as $tagNamespace) {
            if ($this->tagMatchesNamespace($tag, $tagNamespace)) {
                return true;
            }
        }

        return false;
    }

    private function hasEverythingFlush(): bool
    {
        return in_array('*', $this->fullSiteFlushes, true);
    }

    private function tagMatchesNamespace(string $tag, string $tagNamespace): bool
    {
        return $tagNamespace !== '' && str_starts_with($tag, $tagNamespace . '_');
    }

    private function isAbsoluteUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return isset($parts['scheme'], $parts['host']) && $parts['scheme'] !== '' && $parts['host'] !== '';
    }
}
