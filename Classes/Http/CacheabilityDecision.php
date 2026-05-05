<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

final readonly class CacheabilityDecision
{
    private function __construct(
        public bool $cacheable,
        public BypassReason $reason,
        public ?string $matchedRule = null,
    ) {}

    public static function cacheable(): self
    {
        return new self(true, BypassReason::CACHEABLE);
    }

    public static function bypass(BypassReason $reason, ?string $matchedRule = null): self
    {
        return new self(false, $reason, $matchedRule);
    }
}
