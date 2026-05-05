<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * Decides whether LSCache response headers are enabled for the current site's settings.
 */
final class HeaderEmissionDecider
{
    /**
     * Reads `lscache.enabled`, defaulting to enabled when the setting is absent.
     *
     * @param SiteSettings $siteSettings TYPO3 site settings for the current site.
     */
    public function shouldEmitForSite(SiteSettings $siteSettings): bool
    {
        return (bool)$siteSettings->get('lscache.enabled', true);
    }
}
