<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class SiteTagNamespaceResolver implements TagNamespaceResolverInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    public function resolveForSite(string $siteIdentifier): string
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            return $siteIdentifier;
        }

        $tagNamespace = trim((string)$site->getSettings()->get('lscache.tagNamespace', $siteIdentifier));

        return $tagNamespace !== '' ? $tagNamespace : $siteIdentifier;
    }
}
