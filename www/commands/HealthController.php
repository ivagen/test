<?php

declare(strict_types=1);

namespace app\commands;

use app\services\HealthChecker;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * `php yii health/check` -- the Compose healthcheck for the `app` service.
 *
 * It reuses HealthChecker, so the container healthcheck and GET /readyz can never drift
 * apart and report different opinions about the same system.
 */
final class HealthController extends Controller
{
    public function actionCheck(): int
    {
        $checker = new HealthChecker();

        $checks = $checker->readiness() + ['php-fpm' => $checker->phpFpm()];
        $healthy = HealthChecker::allPassed($checks);

        foreach ($checks as $name => $result) {
            $this->stdout(sprintf(
                "%-10s %s%s\n",
                $name,
                $result['ok'] ? 'ok' : 'FAIL',
                isset($result['error']) ? ' (' . $result['error'] . ')' : '',
            ));
        }

        return $healthy ? ExitCode::OK : ExitCode::UNAVAILABLE;
    }
}
