<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Removes old purge audit rows according to the configured retention window.
 *
 * @internal
 */
final class PurgeLogRetentionTask extends AbstractTask
{
    private const DEFAULT_RETENTION_DAYS = 7;

    public function __construct(
        private ?PurgeLogRepository $purgeLogRepository = null,
        private ?SiteFinder $siteFinder = null,
    ) {}

    public function execute()
    {
        $repository = $this->purgeLogRepository ??= GeneralUtility::makeInstance(PurgeLogRepository::class);

        try {
            $retentionDaysBySite = $this->resolveRetentionDaysBySite();

            foreach ($retentionDaysBySite as $siteIdentifier => $retentionDays) {
                $cutoff = new DateTimeImmutable(sprintf('-%d days', $retentionDays));
                $repository->purgeOlderThanBySiteIdentifier($siteIdentifier, $cutoff);
            }

            $defaultCutoff = new DateTimeImmutable(sprintf('-%d days', self::DEFAULT_RETENTION_DAYS));
            $repository->purgeOlderThanForOtherSites($defaultCutoff, array_keys($retentionDaysBySite));

            return true;
        } catch (Exception) {
            return false;
        }
    }

    public function getTaskParameters(): array
    {
        return [];
    }

    public function setTaskParameters(array $parameters): void
    {
    }

    public function getAdditionalInformation()
    {
        $retentionDaysBySite = $this->resolveRetentionDaysBySite();
        if ($retentionDaysBySite === []) {
            return sprintf('Purges audit rows older than %d days.', self::DEFAULT_RETENTION_DAYS);
        }
        if (count($retentionDaysBySite) === 1) {
            return sprintf('Purges audit rows older than %d days.', (int)array_values($retentionDaysBySite)[0]);
        }

        return sprintf(
            'Purges audit rows using per-site retentionDays settings (default %d days for unknown sites).',
            self::DEFAULT_RETENTION_DAYS,
        );
    }

    /**
     * @return array<string, int>
     */
    private function resolveRetentionDaysBySite(): array
    {
        $retentionDaysBySite = [];
        $siteFinder = $this->siteFinder ??= GeneralUtility::makeInstance(SiteFinder::class);
        foreach ($siteFinder->getAllSites() as $site) {
            $retentionDays = $this->getSetting($site->getSettings(), 'lscache.audit.retentionDays', null);
            $parsedRetentionDays = $this->parsePositiveInt($retentionDays);
            if ($parsedRetentionDays === null) {
                continue;
            }

            $siteIdentifier = trim($site->getIdentifier());
            if ($siteIdentifier === '') {
                continue;
            }

            $retentionDaysBySite[$siteIdentifier] = $parsedRetentionDays;
        }

        return $retentionDaysBySite;
    }

    private function getSetting(SiteSettings $siteSettings, string $path, mixed $default): mixed
    {
        $value = $siteSettings->get($path, null);
        if ($value !== null) {
            return $value;
        }

        $nested = $siteSettings->getAll();
        foreach (explode('.', $path) as $part) {
            if (!is_array($nested) || !array_key_exists($part, $nested)) {
                return $default;
            }
            $nested = $nested[$part];
        }

        return $nested;
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return null;
    }
}
