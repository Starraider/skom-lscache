<?php

declare(strict_types=1);

return [
    'dependencies' => [
        'backend',
    ],
    'imports' => [
        '@skom/lscache/backend-module.js' => 'EXT:skom_lscache/Resources/Public/JavaScript/backend-module.js',
    ],
];
