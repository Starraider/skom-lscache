<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

use DateTimeInterface;

/**
 * @internal
 */
final readonly class PurgeLogDateRangeFilter
{
    public function __construct(
        private ?DateTimeInterface $from = null,
        private ?DateTimeInterface $to = null,
    ) {}

    public function getFrom(): ?DateTimeInterface
    {
        return $this->from;
    }

    public function getTo(): ?DateTimeInterface
    {
        return $this->to;
    }
}
