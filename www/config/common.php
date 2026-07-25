<?php

declare(strict_types=1);

use app\components\Environment;

/**
 * Configuration shared by the web application, the console application and the tests.
 *
 * Everything environment-specific is read through {@see Environment}, which fails loudly
 * on a missing variable and refuses to start production with a placeholder secret. No
 * credential is hard-coded here -- the 2017 configuration committed both the database
 * password and the cookie validation key.
 */
return [
    'basePath' => dirname(__DIR__),
    'timeZone' => 'UTC',
    'language' => 'en-US',
    'sourceLanguage' => 'en-US',
    'components' => [
        'db' => [
            'class' => yii\db\Connection::class,
            'dsn' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                Environment::string('DB_HOST', 'postgres'),
                Environment::int('DB_PORT', 5432),
                Environment::string('DB_NAME', 'app'),
            ),
            'username' => Environment::string('DB_USER', 'app'),
            'password' => Environment::secret('DB_PASSWORD'),
            'charset' => 'utf8',
            // Safe in dev, and the schema is tiny; production reads it from the file cache.
            'enableSchemaCache' => Environment::isProduction(),
            'schemaCacheDuration' => 3600,
            'schemaCache' => 'cache',
        ],
        'redis' => [
            'class' => yii\redis\Connection::class,
            'hostname' => Environment::string('REDIS_HOST', 'redis'),
            'port' => Environment::int('REDIS_PORT', 6379),
            'database' => Environment::int('REDIS_DB', 0),
            // Redis carries only transient fan-out, so a slow Redis must never stall a
            // request that has already committed to PostgreSQL.
            'connectionTimeout' => 2,
            'dataTimeout' => 2,
        ],
        'cache' => [
            'class' => yii\caching\FileCache::class,
        ],
        'log' => [
            'traceLevel' => 0,
            'targets' => [
                [
                    // Structured JSON on stderr: `docker compose logs app` is the one place
                    // to look, and nothing is written into a container filesystem that
                    // disappears on recreate.
                    'class' => app\components\JsonLogTarget::class,
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                    // Expected client mistakes (404, 422, ...) are not operator problems.
                    'except' => ['yii\web\HttpException:4*'],
                ],
                [
                    // Informational events the application itself raises -- notably a
                    // failed real-time publication, which is degraded observability rather
                    // than a request failure. Yii's own per-query `info` logging stays off.
                    'class' => app\components\JsonLogTarget::class,
                    'levels' => ['info'],
                    'categories' => ['app\*'],
                    'logVars' => [],
                ],
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];
