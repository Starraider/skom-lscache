<?php

declare(strict_types=1);

return [
    'frontend' => [
        'skom/lscache/response-header' => [
            'target' => \Skom\Lscache\Http\Middleware\SkomLscacheMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
        'skom/lscache/loopback-endpoint' => [
            'target' => \Skom\Lscache\Http\Middleware\LoopbackEndpointMiddleware::class,
            'after' => [
                'skom/lscache/response-header',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
