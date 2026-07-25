<?php

declare(strict_types=1);

use app\components\Environment;
use yii\helpers\ArrayHelper;

$common = require __DIR__ . '/common.php';

return ArrayHelper::merge($common, [
    'id' => 'editable-list',
    'bootstrap' => ['log'],
    'components' => [
        'request' => [
            // Signs the CSRF cookie. Sourced from the environment and validated by
            // Environment::secret(), which refuses production placeholders.
            'cookieValidationKey' => Environment::secret('APP_COOKIE_VALIDATION_KEY'),
            'enableCsrfValidation' => true,
            'csrfParam' => '_csrf',
            'csrfCookie' => [
                'httpOnly' => true,
                'sameSite' => yii\web\Cookie::SAME_SITE_STRICT,
                'secure' => Environment::isProduction(),
            ],
            // The API speaks JSON only; this turns a JSON body into request body params.
            'parsers' => [
                'application/json' => yii\web\JsonParser::class,
            ],
            // TLS terminates at a reverse proxy, so trust its forwarding headers -- this
            // is what lets the application know the public scheme is https and emit a
            // wss:// WebSocket URL with no mixed content (spec US3 scenario 4).
            'trustedHosts' => ['any'],
            'secureHeaders' => [
                'X-Forwarded-For',
                'X-Forwarded-Host',
                'X-Forwarded-Proto',
                'X-Real-IP',
            ],
        ],
        'response' => [
            'formatters' => [
                yii\web\Response::FORMAT_JSON => [
                    'class' => yii\web\JsonResponseFormatter::class,
                    'prettyPrint' => false,
                ],
            ],
        ],
        'errorHandler' => [
            'class' => app\components\ErrorHandler::class,
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            // Anything not listed below is a 404 rather than an accidentally reachable
            // controller action.
            'enableStrictParsing' => true,
            'rules' => [
                '' => 'site/index',

                'GET healthz' => 'health/live',
                'GET readyz' => 'health/ready',

                'GET api/items' => 'api-item/index',
                'POST api/items' => 'api-item/create',
                // `[1-9]\d*` enforces the OpenAPI `minimum: 1` at the routing layer, so
                // /api/items/0 and /api/items/abc never reach a controller.
                'PUT api/items/<id:[1-9]\d*>' => 'api-item/update',
                'DELETE api/items/<id:[1-9]\d*>' => 'api-item/delete',
            ],
        ],
        'assetManager' => [
            // The browser bundle is built by Vite; Yii publishes nothing.
            'bundles' => false,
        ],
    ],
]);
