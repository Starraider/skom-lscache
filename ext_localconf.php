<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['LOG']['skom']['lscache']['writerConfiguration'] = [
    \Psr\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
            'logFileInfix' => 'skom_lscache',
        ],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][
    \Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener::class
] = \Skom\Lscache\Purge\EventListener\DataHandlerRecordSavedListener::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][
    \Skom\Lscache\Purge\EventListener\DataHandlerRecordDeletedListener::class
] = \Skom\Lscache\Purge\EventListener\DataHandlerRecordDeletedListener::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][
    \Skom\Lscache\Purge\EventListener\DataHandlerRecordMovedListener::class
] = \Skom\Lscache\Purge\EventListener\DataHandlerRecordMovedListener::class;
