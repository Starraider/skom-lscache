<?php

declare(strict_types=1);

use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Enum\PurgeSource;

return [
    'ctrl' => [
        'title' => 'LSCache purge log',
        'label' => 'request_id',
        'crdate' => 'crdate',
        'hideTable' => true,
        'rootLevel' => 1,
        'default_sortby' => 'crdate DESC',
        'typeicon_classes' => [
            'default' => 'mimetypes-other-other',
        ],
        'security' => [
            'ignoreWebMountRestriction' => true,
            'ignoreRootLevelRestriction' => true,
        ],
    ],
    'columns' => [
        'pid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'crdate' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'site_identifier' => [
            'label' => 'Site identifier',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'eval' => 'trim',
            ],
        ],
        'source' => [
            'label' => 'Source',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'DataHandler', 'value' => PurgeSource::DATAHANDLER->value],
                    ['label' => 'CLI', 'value' => PurgeSource::CLI->value],
                    ['label' => 'Module', 'value' => PurgeSource::MODULE->value],
                    ['label' => 'API', 'value' => PurgeSource::API->value],
                    ['label' => 'Cache flush event', 'value' => PurgeSource::CACHE_FLUSH_EVENT->value],
                ],
                'default' => PurgeSource::DATAHANDLER->value,
            ],
        ],
        'trigger_ref' => [
            'label' => 'Trigger reference',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'tags' => [
            'label' => 'Tags JSON',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'cols' => 40,
            ],
        ],
        'urls' => [
            'label' => 'URLs JSON',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'cols' => 40,
            ],
        ],
        'tag_count' => [
            'label' => 'Tag count',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 0,
            ],
        ],
        'outcome' => [
            'label' => 'Outcome',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'enqueued', 'value' => PurgeLogOutcome::ENQUEUED->value],
                    ['label' => 'flushed', 'value' => PurgeLogOutcome::FLUSHED->value],
                    ['label' => 'truncated', 'value' => PurgeLogOutcome::TRUNCATED->value],
                    ['label' => 'failed', 'value' => PurgeLogOutcome::FAILED->value],
                    ['label' => 'skipped', 'value' => PurgeLogOutcome::SKIPPED->value],
                ],
                'default' => PurgeLogOutcome::ENQUEUED->value,
            ],
        ],
        'latency_ms' => [
            'label' => 'Latency (ms)',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 0,
            ],
        ],
        'request_id' => [
            'label' => 'Request ID',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 36,
                'eval' => 'trim',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                site_identifier,
                source,
                trigger_ref,
                tags,
                urls,
                tag_count,
                outcome,
                latency_ms,
                request_id,
            ',
        ],
    ],
];
