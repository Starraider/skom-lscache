<?php

declare(strict_types=1);

return [
    'lscache:purge' => [
        'class' => \Skom\Lscache\Command\PurgeCommand::class,
    ],
    'lscache:status' => [
        'class' => \Skom\Lscache\Command\StatusCommand::class,
    ],
];
