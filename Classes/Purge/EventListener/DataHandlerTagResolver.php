<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\EventListener;

use Skom\Lscache\Http\RecordTagReference;
use Skom\Lscache\Http\TagSchema;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class DataHandlerTagResolver
{
    private const array DEFAULT_ALLOWED_TABLES = ['pages', 'tt_content'];

    public function __construct(
        private SiteFinder $siteFinder,
        private TagSchema $tagSchema,
    ) {}

    /**
     * @param list<int> $pageIds
     * @return list<string>
     */
    public function resolveTagsForRecord(string $table, int $recordUid, array $pageIds): array
    {
        $tags = [];
        foreach (array_values(array_unique(array_filter($pageIds, static fn (int $pageId): bool => $pageId > 0))) as $pageId) {
            $site = $this->resolveSite($pageId);
            if ($site === null || !$this->isAutoPurgeEnabled($site->getSettings()) || !$this->isAllowedTable($site->getSettings(), $table)) {
                continue;
            }

            $namespace = trim((string)$site->getSettings()->get('lscache.tagNamespace', ''));
            if ($namespace === '') {
                $namespace = $site->getIdentifier();
            }

            $tagSet = $this->tagSchema->computeTagsForPage(
                $site->getIdentifier(),
                $namespace,
                $pageId,
                [new RecordTagReference($table, $recordUid)],
            );

            foreach ($tagSet->tags as $tag) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * @param list<int> $pageIds
     */
    public function resolveSingleSiteIdentifierForPageIds(array $pageIds): ?string
    {
        $siteIdentifiers = [];
        foreach (array_values(array_unique(array_filter($pageIds, static fn (int $pageId): bool => $pageId > 0))) as $pageId) {
            $site = $this->resolveSite($pageId);
            if ($site === null) {
                continue;
            }

            $siteIdentifiers[$site->getIdentifier()] = true;
        }

        if (count($siteIdentifiers) !== 1) {
            return null;
        }

        return array_key_first($siteIdentifiers);
    }

    private function isAutoPurgeEnabled(SiteSettings $siteSettings): bool
    {
        return (bool)$siteSettings->get('lscache.autopurge.dataHandler', true);
    }

    private function isAllowedTable(SiteSettings $siteSettings, string $table): bool
    {
        $configured = $siteSettings->get('lscache.autopurge.tables', []);
        $merged = self::DEFAULT_ALLOWED_TABLES;

        if (is_array($configured)) {
            foreach ($configured as $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $merged[] = $value;
                }
            }
        }

        return in_array($table, array_values(array_unique($merged)), true);
    }

    private function resolveSite(int $pageId): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (\Throwable) {
            return null;
        }
    }
}
