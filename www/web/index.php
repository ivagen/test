<?php

declare(strict_types=1);

/**
 * Web entry script -- the ONLY PHP file nginx will execute (see docker/nginx/default.conf).
 *
 * The 2017 version hard-coded YII_DEBUG=true and YII_ENV='dev', which meant a deployment
 * shipped with the Yii debug error page enabled. Both now derive from APP_ENV, so
 * production is debug-off by construction rather than by remembering to edit this file.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

defined('YII_ENV') or define('YII_ENV', app\components\Environment::isProduction() ? 'prod' : 'dev');
defined('YII_DEBUG') or define('YII_DEBUG', YII_ENV !== 'prod');

require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

$config = require dirname(__DIR__) . '/config/web.php';

(new yii\web\Application($config))->run();
