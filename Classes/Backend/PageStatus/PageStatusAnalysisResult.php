<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\PageStatus;

use Skom\Lscache\Http\BypassReason;

final readonly class PageStatusAnalysisResult
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public int $pageUid,
        public string $pageTitle,
        public string $pageSlug,
        public string $siteIdentifier,
        public string $siteLabel,
        public string $tagNamespace,
        public bool $cacheable,
        public BypassReason $reason,
        public ?string $matchedRule,
        public int $effectiveTtl,
        public array $tags,
        public int $originalTagCount,
        public bool $truncated,
        public string $probeTargetUrl,
    ) {}
}
