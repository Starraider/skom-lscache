<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use InvalidArgumentException;

/**
 * Immutable positive TTL value supplied by listeners that override default LSCache cache control.
 */
final readonly class CacheControlOverride
{
    /**
     * @param int $ttl Positive cache lifetime in seconds.
     *
     * @api
     */
    public function __construct(public int $ttl)
    {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('Cache control override TTL must be greater than zero.', 1439319779);
        }
    }
}
