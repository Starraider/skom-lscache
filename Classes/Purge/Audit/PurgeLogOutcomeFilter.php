<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

/**
 * @internal
 */
final readonly class PurgeLogOutcomeFilter
{
    /**
     * @param list<PurgeLogOutcome> $outcomes
     */
    public function __construct(
        private array $outcomes = [],
    ) {}

    /**
     * @return list<PurgeLogOutcome>
     */
    public function getOutcomes(): array
    {
        return $this->outcomes;
    }
}
