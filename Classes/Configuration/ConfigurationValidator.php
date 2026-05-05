<?php

declare(strict_types=1);

namespace Skom\Lscache\Configuration;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;

/**
 * Validates and normalizes LSCache site settings when TYPO3 loads site configuration.
 */
#[AsEventListener(identifier: 'skom-lscache-site-config-validator')]
final class ConfigurationValidator
{
    /**
     * @var array<string, string>
     */
    private array $siteIdentifiersByTagNamespace = [];

    /**
     * Checks one loaded site configuration and mutates missing derived defaults.
     *
     * @param SiteConfigurationLoadedEvent $event TYPO3 event containing the site identifier and mutable site configuration.
     */
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

        $tagNamespace = (string)$settings['tagNamespace'];
        $existingSiteIdentifier = $this->siteIdentifiersByTagNamespace[$tagNamespace] ?? null;
        if ($existingSiteIdentifier !== null && $existingSiteIdentifier !== $siteIdentifier) {
            throw InvalidSiteSettingException::forConstraint(
                $siteIdentifier,
                'lscache.tagNamespace',
                sprintf(
                    'must be unique across sites; namespace "%s" is already used by site "%s"',
                    $tagNamespace,
                    $existingSiteIdentifier,
                ),
            );
        }
        $this->siteIdentifiersByTagNamespace[$tagNamespace] = $siteIdentifier;

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

    /**
     * Extracts the nested `settings.lscache` array from a TYPO3 site configuration.
     *
     * @param array<string, mixed> $siteConfiguration Raw site configuration provided by TYPO3.
     * @return array<string, mixed>
     */
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
