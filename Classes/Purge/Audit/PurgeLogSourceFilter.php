<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

use Skom\Lscache\Purge\Enum\PurgeSource;

/**
 * @internal
 */
final readonly class PurgeLogSourceFilter
{
    /**
     * @param list<PurgeSource> $sources
     */
    public function __construct(
        private array $sources = [],
    ) {}

    /**
     * @return list<PurgeSource>
     */
    public function getSources(): array
    {
        return $this->sources;
    }
}
