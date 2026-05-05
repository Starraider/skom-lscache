<?php

declare(strict_types=1);

namespace Skom\Lscache\Configuration;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;

#[AsEventListener(identifier: 'skom-lscache-site-config-validator')]
final class ConfigurationValidator
{
    public function __invoke(SiteConfigurationLoadedEvent $event): void
    {
        $configuration = $event->getConfiguration();
        $settings = $this->extractLscacheSettings($configuration);
        $siteIdentifier = $event->getSiteIdentifier();
        if ($settings === []) {
            return;
        }

        if (trim((string)($settings['tagNamespace'] ?? '')) === '') {
            $configuration['settings']['lscache']['tagNamespace'] = $siteIdentifier;
            $event->setConfiguration($configuration);
            $settings['tagNamespace'] = $siteIdentifier;
        }

        if (($settings['defaultTtl'] ?? 0) <= 0) {
            throw InvalidSiteSettingException::forConstraint(
                $siteIdentifier,
                'lscache.defaultTtl',
                'must be greater than 0',
            );
        }

        $loopbackSettings = $settings['loopback'] ?? [];
        if (($loopbackSettings['rateLimit'] ?? 0) <= 0) {
            throw InvalidSiteSettingException::forConstraint(
                $siteIdentifier,
                'lscache.loopback.rateLimit',
                'must be greater than 0',
            );
        }

        $cacheFlushMirrorEnabled = (bool)($settings['mirror']['cacheFlushEvent'] ?? false);
        if ($cacheFlushMirrorEnabled && trim((string)($loopbackSettings['secret'] ?? '')) === '') {
            throw InvalidSiteSettingException::forConstraint(
                $siteIdentifier,
                'lscache.loopback.secret',
                'must not be empty when lscache.mirror.cacheFlushEvent is enabled',
            );
        }
    }

    private function extractLscacheSettings(array $siteConfiguration): array
    {
        $settings = $siteConfiguration['settings'] ?? [];
        if (!is_array($settings)) {
            return [];
        }

        $lscache = $settings['lscache'] ?? [];
        return is_array($lscache) ? $lscache : [];
    }
}
