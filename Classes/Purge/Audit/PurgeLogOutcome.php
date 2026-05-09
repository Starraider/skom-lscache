<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

/**
 * @internal
 */
enum PurgeLogOutcome: string
{
    case ENQUEUED = 'enqueued';
    case FLUSHED = 'flushed';
    case TRUNCATED = 'truncated';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
