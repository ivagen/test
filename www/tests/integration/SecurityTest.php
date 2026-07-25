<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\components\Environment;
use app\components\JsonLogTarget;
use app\tests\Support\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * Secure defaults (tasks.md T027/T032/T057, FR-008, FR-010, Constitution IV).
 */
final class SecurityTest extends TestCase
{
    private HttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = HttpClient::fromEnvironment();
    }

    /**
     * The single most important regression guard in this suite: no error response, in any
     * environment, may carry a trace, a filesystem path, SQL or a credential.
     */
    #[DataProvider('errorProducingRequests')]
    public function testErrorResponsesLeakNothing(string $method, string $path): void
    {
        $response = $this->http->json($method, $path);

        self::assertGreaterThanOrEqual(400, $response->status);

        foreach ([
            'Stack trace',
            '#0 ',
            '/var/www',
            'vendor/yiisoft',
            'yii\\base',
            'PDOException',
            'SQLSTATE',
            'SELECT ',
            'pgsql:host',
            'password',
        ] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $response->body,
                sprintf('%s %s leaked "%s"', $method, $path, $forbidden),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorProducingRequests(): iterable
    {
        yield 'unknown api path' => ['GET', '/api/unknown'];
        yield 'unknown item' => ['PUT', '/api/items/2147483000'];
        yield 'forged mutation' => ['POST', '/api/items'];
        yield 'unknown page' => ['GET', '/does-not-exist'];
        yield 'traversal attempt' => ['GET', '/../config/common.php'];
    }

    /**
     * Strict URL parsing means an unlisted route is a 404, not an accidentally reachable
     * controller action.
     */
    #[DataProvider('routesThatMustNotExist')]
    public function testRemovedAndInternalRoutesAreNotReachable(string $path): void
    {
        $response = $this->http->request('GET', $path);

        self::assertContains(
            $response->status,
            [301, 403, 404],
            sprintf('%s must not be reachable (got %d)', $path, $response->status),
        );

        // Whatever the status, it must never be a successful API response.
        self::assertStringNotContainsString('"items"', $response->body);
        self::assertStringNotContainsString('"rows"', $response->body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routesThatMustNotExist(): iterable
    {
        // The 2017 query routes, removed under FR-015 (see README's migration note).
        yield 'legacy get' => ['/index.php?r=site/get'];
        yield 'legacy create' => ['/index.php?r=site/create'];
        yield 'legacy update' => ['/index.php?r=site/update&id=1'];
        yield 'legacy delete' => ['/index.php?r=site/delete&id=1'];
        // Gii and the debug toolbar must never be exposed.
        yield 'gii' => ['/gii'];
        yield 'debug' => ['/debug'];
        // Nothing outside web/ may be served, and no arbitrary PHP may execute.
        yield 'config' => ['/config/common.php'];
        yield 'composer manifest' => ['/composer.json'];
        yield 'env file' => ['/.env'];
        yield 'git metadata' => ['/.git/config'];
        yield 'vendor' => ['/vendor/autoload.php'];
        yield 'runtime logs' => ['/runtime/logs/app.log'];
    }

    /**
     * Constitution IV / FR-010: the browser must receive the hardening headers.
     */
    #[DataProvider('requiredSecurityHeaders')]
    public function testSecurityHeadersArePresent(string $header, string $expectedFragment): void
    {
        $value = (string) $this->http->request('GET', '/')->header($header);

        self::assertStringContainsString($expectedFragment, $value, $header . ' is missing or weak.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requiredSecurityHeaders(): iterable
    {
        yield 'nosniff' => ['X-Content-Type-Options', 'nosniff'];
        yield 'framing' => ['X-Frame-Options', 'DENY'];
        yield 'referrer' => ['Referrer-Policy', 'no-referrer'];
        yield 'csp scripts' => ['Content-Security-Policy', "script-src 'self'"];
        yield 'csp framing' => ['Content-Security-Policy', "frame-ancestors 'none'"];
    }

    /**
     * The CSP must not contain an inline escape hatch; the whole client is a bundled
     * module and the CSRF token travels in a meta tag precisely so this can hold.
     */
    public function testTheContentSecurityPolicyForbidsInlineScript(): void
    {
        $csp = (string) $this->http->request('GET', '/')->header('Content-Security-Policy');

        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function testThePageContainsNoInlineScript(): void
    {
        $html = $this->http->request('GET', '/')->body;

        self::assertStringNotContainsString('onclick=', $html);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>[^<]*\S/', $html);
    }

    public function testTheServerVersionIsNotAdvertised(): void
    {
        $response = $this->http->request('GET', '/');

        self::assertNull($response->header('X-Powered-By'), 'PHP must not advertise itself.');
        self::assertStringNotContainsString('nginx/', (string) $response->header('Server'));
    }

    /**
     * The CSRF cookie must not be readable by script, so an XSS elsewhere cannot harvest it.
     */
    public function testTheCsrfCookieIsHttpOnly(): void
    {
        $cookies = $this->http->request('GET', '/')->headers['set-cookie'] ?? [];

        $csrfCookie = null;

        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, '_csrf')) {
                $csrfCookie = $cookie;
            }
        }

        self::assertNotNull($csrfCookie, 'The page must set a CSRF cookie.');
        self::assertStringContainsStringIgnoringCase('httponly', $csrfCookie);
        self::assertStringContainsStringIgnoringCase('samesite=strict', $csrfCookie);
    }

    /**
     * Constitution IV: the committed 2017 cookie key must be impossible to reuse in
     * production, because it has been public in this repository's history.
     */
    public function testProductionRefusesTheLegacyAndPlaceholderSecrets(): void
    {
        $originalEnv = getenv('APP_ENV');
        $originalKey = getenv('APP_COOKIE_VALIDATION_KEY');

        putenv('APP_ENV=prod');

        try {
            foreach (['aehsrykdyulfy', 'dev-only-insecure-placeholder-change-me', 'changeme'] as $insecure) {
                putenv('APP_COOKIE_VALIDATION_KEY=' . $insecure);

                try {
                    Environment::secret('APP_COOKIE_VALIDATION_KEY');
                    self::fail(sprintf('Production accepted the insecure secret "%s".', $insecure));
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('placeholder', $exception->getMessage());
                }
            }

            putenv('APP_COOKIE_VALIDATION_KEY=' . base64_encode(random_bytes(24)));
            self::assertNotSame('', Environment::secret('APP_COOKIE_VALIDATION_KEY'));
        } finally {
            putenv($originalEnv === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnv);
            putenv($originalKey === false ? 'APP_COOKIE_VALIDATION_KEY' : 'APP_COOKIE_VALIDATION_KEY=' . $originalKey);
        }
    }

    public function testProductionRefusesAMissingSecretEntirely(): void
    {
        $originalEnv = getenv('APP_ENV');
        $originalKey = getenv('APP_COOKIE_VALIDATION_KEY');

        putenv('APP_ENV=prod');
        putenv('APP_COOKIE_VALIDATION_KEY=');

        try {
            $this->expectException(\RuntimeException::class);
            Environment::secret('APP_COOKIE_VALIDATION_KEY');
        } finally {
            putenv($originalEnv === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnv);
            putenv($originalKey === false ? 'APP_COOKIE_VALIDATION_KEY' : 'APP_COOKIE_VALIDATION_KEY=' . $originalKey);
        }
    }

    /**
     * A credential that reaches the logger by accident must be masked before it is written.
     */
    public function testSecretsAreRedactedFromLogs(): void
    {
        $password = getenv('DB_PASSWORD');

        self::assertIsString($password);
        self::assertNotSame('', $password);

        $file = tempnam(sys_get_temp_dir(), 'logtarget');
        self::assertIsString($file);

        try {
            $target = new JsonLogTarget();
            $target->streamUri = $file;
            $target->messages = [[
                'Connection failed using password ' . $password . ' -- retrying',
                Logger::LEVEL_WARNING,
                'test',
                microtime(true),
                [],
            ]];

            $target->export();

            $written = file_get_contents($file);

            self::assertIsString($written);
            self::assertStringNotContainsString($password, $written, 'The password must never be written to a log.');
            self::assertStringContainsString('[redacted]', $written);

            // The record must still be valid, useful JSON -- redaction may not corrupt it.
            $record = json_decode(trim($written), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('warning', $record['level']);
            self::assertStringContainsString('Connection failed', $record['message']);
        } finally {
            @unlink($file);
        }
    }
}
