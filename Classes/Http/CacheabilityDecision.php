<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

/**
 * Immutable result object returned by the cacheability classifier.
 */
final readonly class CacheabilityDecision
{
    /**
     * @param bool $cacheable Whether the response may be cached publicly by LSCache.
     * @param BypassReason $reason Machine-readable cacheability or bypass reason.
     * @param string|null $matchedRule Optional bypass rule value that matched the request or page inventory.
     */
    private function __construct(
        public bool $cacheable,
        public BypassReason $reason,
        public ?string $matchedRule = null,
    ) {}

    /**
     * Creates a decision for a response that may receive public LSCache headers.
     */
    public static function cacheable(): self
    {
        return new self(true, BypassReason::CACHEABLE);
    }

    /**
     * Creates a decision for a response that must be private or bypass LSCache.
     *
     * @param BypassReason $reason Specific bypass reason.
     * @param string|null $matchedRule Optional configured rule that caused the bypass.
     */
    public static function bypass(BypassReason $reason, ?string $matchedRule = null): self
    {
        return new self(false, $reason, $matchedRule);
    }
}
