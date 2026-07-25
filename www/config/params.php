<?php

declare(strict_types=1);

use app\components\Environment;

return [
    // Redis Pub/Sub channel carrying ItemEvent messages from php-fpm to the worker.
    'realtime.channel' => Environment::string('REALTIME_CHANNEL', 'items.events'),
    // Ports are internal only; the browser always reaches the worker through nginx at /ws.
    'realtime.wsPort' => Environment::int('REALTIME_WS_PORT', 8080),
    'realtime.healthPort' => Environment::int('REALTIME_HEALTH_PORT', 8081),
    // Matches contracts/openapi.yaml and data-model.md.
    'item.nameMaxLength' => 255,
];
