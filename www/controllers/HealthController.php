<?php

declare(strict_types=1);

namespace app\controllers;

use app\services\HealthChecker;
use yii\web\Controller;
use yii\web\Response;

/**
 * Liveness and readiness endpoints (FR-009).
 *
 * The split matters operationally:
 *
 *  - /healthz (liveness) answers "is this process running?". It must NOT touch PostgreSQL
 *    or Redis, otherwise a brief database blip would make an orchestrator kill perfectly
 *    healthy web containers and turn a small outage into a large one.
 *  - /readyz (readiness) answers "can this process serve requests?" and therefore does
 *    check both dependencies, returning 503 when either is down.
 */
final class HealthController extends Controller
{
    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        $this->response->format = Response::FORMAT_JSON;
        // Health output must never be served from an intermediary cache.
        $this->response->headers->set('Cache-Control', 'no-store');

        return parent::beforeAction($action);
    }

    /**
     * @return array{status: string}
     */
    public function actionLive(): array
    {
        return ['status' => 'ok'];
    }

    /**
     * @return array{status: string, checks: array<string, array{ok: bool, error?: string}>}
     */
    public function actionReady(): array
    {
        $checks = (new HealthChecker())->readiness();
        $ready = HealthChecker::allPassed($checks);

        if (!$ready) {
            $this->response->setStatusCode(503);
        }

        return [
            'status' => $ready ? 'ready' : 'unavailable',
            'checks' => $checks,
        ];
    }
}
