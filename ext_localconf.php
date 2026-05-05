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
