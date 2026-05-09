<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

/**
 * @internal
 */
final readonly class PurgeLogSiteFilter
{
    /**
     * @param list<string>|string|null $siteIdentifiers
     */
    public function __construct(
        private array|string|null $siteIdentifiers = null,
    ) {}

    public function getSiteIdentifier(): ?string
    {
        $siteIdentifiers = $this->getSiteIdentifiers();

        return $siteIdentifiers[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getSiteIdentifiers(): array
    {
        if (is_string($this->siteIdentifiers)) {
            $siteIdentifier = trim($this->siteIdentifiers);

            return $siteIdentifier !== '' ? [$siteIdentifier] : [];
        }

        if (!is_array($this->siteIdentifiers)) {
            return [];
        }

        $normalized = [];
        foreach ($this->siteIdentifiers as $siteIdentifier) {
            $siteIdentifier = trim((string)$siteIdentifier);
            if ($siteIdentifier !== '') {
                $normalized[$siteIdentifier] = true;
            }
        }

        return array_keys($normalized);
    }
}
