<?php

declare(strict_types=1);

$EM_CONF['skom_lscache'] = [
    'title' => 'LiteSpeed Cache',
    'description' => 'LiteSpeed Cache integration for TYPO3 — emits per-response LSCache tag headers and orchestrates tag-targeted purges.',
    'category' => 'fe',
    'author' => 'Sven Kalbhenn',
    'author_email' => 'sven@skom.de',
    'author_company' => 'skom',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'php' => '8.2.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
