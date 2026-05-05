<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\Channel;

/**
 * Computes deterministic LSCache tag names for sites, pages, and record references.
 */
final readonly class TagSchema
{
    private const int MAX_TAG_COUNT = 64;

    /**
     * @param LoggerInterface $logger Channel logger used for tag truncation warnings.
     */
    public function __construct(
        #[Channel('skom.lscache')]
        private LoggerInterface $logger,
    ) {}

    /**
     * Builds the tag set for a page and optional rendered record references.
     *
     * @param string $siteIdentifier TYPO3 site identifier used for log context.
     * @param string $namespace Header-safe LSCache tag namespace.
     * @param int $pageUid Positive UID of the rendered page.
     * @param array<mixed> $recordReferences RecordTagReference instances that should receive record tags.
     *
     * @api
     */
    public function computeTagsForPage(
        string $siteIdentifier,
        string $namespace,
        int $pageUid,
        array $recordReferences,
    ): TagSet {
        $namespace = trim($namespace);
        if ($namespace === '') {
            throw new InvalidArgumentException('Tag namespace must not be empty.', 2770572367);
        }

        if ($pageUid <= 0) {
            throw new InvalidArgumentException('Page uid must be greater than zero.', 1261469656);
        }

        $siteTag = $namespace . '_site';
        $pageTag = $namespace . '_page_' . $pageUid;

        $tags = [$siteTag, $pageTag];
        $seen = [$siteTag => true, $pageTag => true];

        foreach ($recordReferences as $reference) {
            if (!$reference instanceof RecordTagReference) {
                throw new InvalidArgumentException('All record references must be instances of RecordTagReference.', 9201640248);
            }

            $recordTag = $namespace . '_record_' . $reference->table . '_' . $reference->uid;
            if (isset($seen[$recordTag])) {
                continue;
            }

            $tags[] = $recordTag;
            $seen[$recordTag] = true;
        }

        $originalCount = count($tags);
        if ($originalCount <= self::MAX_TAG_COUNT) {
            return new TagSet($tags, $originalCount, false);
        }

        $this->logger->warning('Tag set truncated to 64 entries.', [
            'site' => $siteIdentifier,
            'source' => 'tag-cap',
            'tagCount' => $originalCount,
            'outcome' => 'truncated',
        ]);

        return new TagSet(array_slice($tags, 0, self::MAX_TAG_COUNT), $originalCount, true);
    }
}
