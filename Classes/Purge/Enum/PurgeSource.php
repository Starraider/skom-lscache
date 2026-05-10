<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Enum;

/** @api */
enum PurgeSource: string
{
    case DATAHANDLER = 'datahandler';
    case CLI = 'cli';
    case MODULE = 'module';
    case API = 'api';
    case CACHE_FLUSH_EVENT = 'cache-flush-event';
}
