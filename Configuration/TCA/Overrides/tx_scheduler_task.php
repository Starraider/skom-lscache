<?php

declare(strict_types=1);

use Skom\Lscache\Purge\Audit\PurgeLogRetentionTask;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

if (isset($GLOBALS['TCA']['tx_scheduler_task'])) {
    ExtensionManagementUtility::addRecordType(
        [
            'label' => 'LSCache audit log retention',
            'description' => 'Deletes audit log rows older than the configured retention window.',
            'value' => PurgeLogRetentionTask::class,
            'icon' => 'mimetypes-x-tx_scheduler_task_group',
            'iconOverlay' => 'content-clock',
            'group' => 'system',
        ],
        '
            --div--;core.form.tabs:general,
                tasktype,
                task_group,
                description,
            --div--;scheduler.messages:scheduler.form.palettes.timing,
                --palette--;;execution,
            --div--;core.form.tabs:access,
                disable,
            --div--;core.form.tabs:extended,
        ',
        [],
        '',
        'tx_scheduler_task'
    );
}
