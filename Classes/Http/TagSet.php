<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

/**
 * Immutable collection of LSCache tags plus metadata about truncation.
 */
final readonly class TagSet
{
    /**
     * @param list<string> $tags Header-safe LSCache tag values in deterministic order.
     * @param int $originalCount Number of tags before applying the 64-tag cap.
     * @param bool $truncated Whether the tag set was truncated to the maximum header-safe count.
     */
    public function __construct(
        public array $tags,
        public int $originalCount,
        public bool $truncated,
    ) {}
}
