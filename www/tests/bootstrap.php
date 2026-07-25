<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap (tasks.md T021).
 *
 * Tests run inside the `app` container, so they read exactly the same environment
 * variables as the running application -- there is no second, drifting configuration.
 */

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@app', dirname(__DIR__));
Yii::setAlias('@tests', __DIR__);
