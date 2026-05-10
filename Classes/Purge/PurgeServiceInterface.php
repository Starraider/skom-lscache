<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

use Skom\Lscache\Purge\Enum\PurgeSource;

/** @api */
interface PurgeServiceInterface
{
    /**
     * @param list<string> $tags
     */
    public function purgeTags(array $tags, PurgeSource $source, ?string $triggerRef = null, ?string $siteIdentifier = null): void;

    public function purgeUrl(string $url, PurgeSource $source, ?string $triggerRef = null): void;

    public function purgeFullSite(string $siteIdentifier, PurgeSource $source, ?string $triggerRef = null): void;

    public function purgeEverything(PurgeSource $source, ?string $triggerRef = null): void;
}
