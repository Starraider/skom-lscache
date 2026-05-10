<?php

declare(strict_types=1);

use Skom\Lscache\Backend\Controller\AuditLogController;
use Skom\Lscache\Backend\Controller\DashboardController;
use Skom\Lscache\Backend\Controller\ManualPurgeController;
use Skom\Lscache\Backend\Controller\PageStatusController;
use Skom\Lscache\Backend\Controller\ProbeController;

return [
    'web_SkomLscacheCache' => [
        'parent' => 'web',
        'position' => [
            'after' => 'web_info',
        ],
        'access' => 'user',
        'path' => '/module/web/lscache',
        'iconIdentifier' => 'module-skom-lscache',
        'labels' => 'LLL:EXT:skom_lscache/Resources/Private/Language/locallang_mod.xlf',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'routes' => [
            '_default' => [
                'target' => DashboardController::class . '::handleRequest',
            ],
            'audit-log' => [
                'target' => AuditLogController::class . '::handleRequest',
            ],
            'audit-log-export' => [
                'target' => AuditLogController::class . '::exportCsv',
                'methods' => ['GET'],
            ],
            'page-status' => [
                'target' => PageStatusController::class . '::handleRequest',
                'methods' => ['GET'],
            ],
            'manual-purge' => [
                'target' => ManualPurgeController::class . '::handleRequest',
                'methods' => ['GET'],
            ],
            'manual-purge-execute' => [
                'target' => ManualPurgeController::class . '::submitRequest',
                'methods' => ['POST'],
            ],
            'probe' => [
                'target' => ProbeController::class . '::handleRequest',
                'methods' => ['POST'],
            ],
        ],
    ],
];
