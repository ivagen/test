<?php

declare(strict_types=1);

namespace app\services;

/**
 * Readiness checks shared by the HTTP endpoints and the container healthcheck, so both
 * report on exactly the same conditions (FR-009).
 *
 * Every check touches its REAL dependency. quickstart.md requires that "health checks fail
 * when their real dependency is unavailable", which a check that only reports "the PHP
 * process is alive" would silently violate -- and that false-healthy state is precisely
 * what spec US1 acceptance scenario 4 is about.
 */
final class HealthChecker
{
    /**
     * @return array<string, array{ok: bool, error?: string}>
     */
    public function readiness(): array
    {
        return [
            'database' => $this->database(),
            'redis' => $this->redis(),
        ];
    }

    /**
     * @param array<string, array{ok: bool, error?: string}> $checks
     */
    public static function allPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function database(): array
    {
        try {
            // A real round trip, not just "is a connection object configured".
            \Yii::$app->getDb()->createCommand('SELECT 1')->queryScalar();

            return ['ok' => true];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $this->safeReason($exception)];
        }
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function redis(): array
    {
        try {
            $response = \Yii::$app->get('redis')->executeCommand('PING');

            return $response === 'PONG' || $response === true
                ? ['ok' => true]
                : ['ok' => false, 'error' => 'Unexpected PING response'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $this->safeReason($exception)];
        }
    }

    /**
     * Confirms php-fpm is actually accepting FastCGI connections. Used by the container
     * healthcheck, where nothing else would notice a wedged pool.
     *
     * @return array{ok: bool, error?: string}
     */
    public function phpFpm(string $host = '127.0.0.1', int $port = 9000): array
    {
        $socket = @fsockopen($host, $port, $code, $message, 3);

        if ($socket === false) {
            return ['ok' => false, 'error' => sprintf('php-fpm is not accepting connections on %s:%d', $host, $port)];
        }

        fclose($socket);

        return ['ok' => true];
    }

    /**
     * A connection failure message can embed a DSN, a host name or credentials. Only the
     * exception class is reported outward; the full detail goes to the structured log,
     * where it is redacted (Constitution IV, FR-010).
     */
    private function safeReason(\Throwable $exception): string
    {
        \Yii::warning(
            sprintf('Health check failed: %s: %s', $exception::class, $exception->getMessage()),
            'app\services\HealthChecker',
        );

        return 'unavailable';
    }
}
