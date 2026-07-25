<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\services\HealthChecker;
use app\tests\Support\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Health and readiness (tasks.md T026, FR-009, spec US1 acceptance scenario 4).
 */
final class HealthTest extends TestCase
{
    private HttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = HttpClient::fromEnvironment();
    }

    public function testLivenessAnswersWithoutTouchingDependencies(): void
    {
        $response = $this->http->json('GET', '/healthz');

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->json()['status']);
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
    }

    public function testReadinessReportsEveryDependency(): void
    {
        $response = $this->http->json('GET', '/readyz');

        self::assertSame(200, $response->status, $response->body);

        $body = $response->json();

        self::assertSame('ready', $body['status']);
        self::assertArrayHasKey('database', $body['checks']);
        self::assertArrayHasKey('redis', $body['checks']);
        self::assertTrue($body['checks']['database']['ok']);
        self::assertTrue($body['checks']['redis']['ok']);
    }

    /**
     * A health endpoint that cannot fail is worse than none: it turns an outage into a
     * silent one. This proves the check really depends on the dependency by pointing it at
     * an address nothing is listening on.
     */
    public function testAFailedDependencyIsReportedAsNotReady(): void
    {
        $application = new \yii\console\Application([
            'id' => 'health-negative-test',
            'basePath' => \Yii::getAlias('@app'),
            'components' => [
                'db' => [
                    'class' => \yii\db\Connection::class,
                    // Port 1 is reserved and never listening.
                    'dsn' => 'pgsql:host=127.0.0.1;port=1;dbname=nothing',
                    'username' => 'nobody',
                    'password' => 'nothing',
                    'attributes' => [\PDO::ATTR_TIMEOUT => 2],
                ],
                'redis' => [
                    'class' => \yii\redis\Connection::class,
                    'hostname' => '127.0.0.1',
                    'port' => 1,
                    'connectionTimeout' => 2,
                ],
            ],
        ]);

        $previous = \Yii::$app;
        \Yii::$app = $application;
        \Yii::$app->getErrorHandler()->unregister();

        try {
            $checks = (new HealthChecker())->readiness();

            self::assertFalse($checks['database']['ok'], 'An unreachable database must fail the check.');
            self::assertFalse($checks['redis']['ok'], 'An unreachable Redis must fail the check.');
            self::assertFalse(HealthChecker::allPassed($checks));

            // FR-010: the failure reason must not carry a DSN, host or credential outward.
            foreach ($checks as $check) {
                self::assertSame('unavailable', $check['error'] ?? null);
            }
        } finally {
            \Yii::$app = $previous;
        }
    }

    /**
     * The console command backing the Compose healthcheck must agree with /readyz, and it
     * additionally verifies php-fpm itself is accepting connections.
     */
    public function testTheContainerHealthCommandSucceedsWhenTheStackIsUp(): void
    {
        $output = [];
        $status = 0;

        exec(sprintf('php %s health/check 2>&1', escapeshellarg(\dirname(__DIR__, 2) . '/yii')), $output, $status);

        $text = implode("\n", $output);

        self::assertSame(0, $status, $text);
        self::assertStringContainsString('database', $text);
        self::assertStringContainsString('redis', $text);
        self::assertStringContainsString('php-fpm', $text);
        self::assertStringNotContainsString('FAIL', $text);
    }
}
