<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class HeaderEmissionDecider
{
    public function shouldEmitForSite(SiteSettings $siteSettings): bool
    {
        return (bool)$siteSettings->get('lscache.enabled', true);
    }
}
