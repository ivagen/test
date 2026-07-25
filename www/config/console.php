<?php

declare(strict_types=1);

use yii\helpers\ArrayHelper;

$common = require __DIR__ . '/common.php';

return ArrayHelper::merge($common, [
    'id' => 'editable-list-console',
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [
        'migrate' => [
            'class' => yii\console\controllers\MigrateController::class,
            // The original migration lives in www/migrations/ and is NEVER re-created or
            // renamed: preserving it is what proves legacy rows survive (FR-001).
            'migrationPath' => '@app/migrations',
            'migrationTable' => 'migration',
        ],
    ],
]);
