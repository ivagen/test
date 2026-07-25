<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\tests\Support\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates contracts/openapi.yaml and holds the running API to it (tasks.md T035).
 *
 * This is the test that keeps the document honest. Without it, the OpenAPI file is prose:
 * it could drift from the implementation in either direction and nothing would notice.
 * Here the document is parsed, checked for structural sanity, and then every operation and
 * response schema it declares is exercised against the live API.
 */
final class OpenApiContractTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /**
     * Inside the container the contracts directory is mounted read-only at
     * /var/www/contracts; outside it, the repository layout applies.
     */
    private static function document(): string
    {
        foreach ([
            \dirname(__DIR__, 2) . '/contracts/openapi.yaml',
            \dirname(__DIR__, 4) . '/contracts/openapi.yaml',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('contracts/openapi.yaml could not be located.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        if (self::$spec === null) {
            $parsed = Yaml::parseFile(self::document());

            \assert(\is_array($parsed));

            self::$spec = $parsed;
        }

        return self::$spec;
    }

    public function testTheDocumentExistsAndParses(): void
    {
        self::assertFileExists(self::document());
        // spec() asserts the parsed shape itself; reaching here without an exception is
        // what proves the YAML is well-formed.
        self::assertNotSame([], self::spec());
    }

    public function testTheDocumentDeclaresASupportedOpenApiVersion(): void
    {
        self::assertMatchesRegularExpression('/^3\.\d+\.\d+$/', (string) self::spec()['openapi']);
        self::assertArrayHasKey('info', self::spec());
        self::assertArrayHasKey('paths', self::spec());
    }

    /**
     * Every `$ref` must resolve. A dangling reference makes a document that looks complete
     * but describes nothing.
     */
    public function testEveryReferenceResolves(): void
    {
        $unresolved = [];

        $walk = static function (mixed $node) use (&$walk, &$unresolved): void {
            if (!\is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if ($key === '$ref' && \is_string($value)) {
                    $path = explode('/', ltrim($value, '#/'));
                    $cursor = self::spec();

                    foreach ($path as $segment) {
                        if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                            $unresolved[] = $value;

                            continue 2;
                        }

                        $cursor = $cursor[$segment];
                    }

                    continue;
                }

                $walk($value);
            }
        };

        $walk(self::spec());

        self::assertSame([], $unresolved, 'Unresolved $ref: ' . implode(', ', $unresolved));
    }

    /**
     * The document must describe exactly the resource surface the application exposes --
     * no undocumented endpoint, and no documented endpoint that does not exist.
     */
    public function testTheDocumentedOperationsMatchTheImplementation(): void
    {
        $documented = [];

        foreach (self::spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (\in_array($method, ['get', 'post', 'put', 'delete', 'patch'], true)) {
                    $documented[] = strtoupper($method) . ' ' . $path;
                }
            }
        }

        sort($documented);

        self::assertSame([
            'DELETE /api/items/{id}',
            'GET /api/items',
            'POST /api/items',
            'PUT /api/items/{id}',
        ], $documented);
    }

    /**
     * Every mutating operation must require the CSRF header. If one ever stopped doing so,
     * the document would be describing an endpoint that is open to cross-site forgery.
     */
    public function testEveryMutationDocumentsTheCsrfHeaderAndA403(): void
    {
        foreach (self::spec()['paths'] as $path => $operations) {
            foreach (['post', 'put', 'delete'] as $method) {
                if (!isset($operations[$method])) {
                    continue;
                }

                $operation = $operations[$method];
                $label = strtoupper($method) . ' ' . $path;

                $parameters = array_map(
                    static fn (array $parameter): string => (string) ($parameter['$ref'] ?? ''),
                    $operation['parameters'] ?? [],
                );

                self::assertContains(
                    '#/components/parameters/CsrfToken',
                    $parameters,
                    $label . ' must require the CSRF token.',
                );

                self::assertArrayHasKey('403', $operation['responses'], $label . ' must document a 403.');
            }
        }
    }

    /**
     * Now the live check: every status code the document promises is actually produced.
     *
     * @param array<string, mixed>|null $payload
     * @param array<string, string>     $headers
     */
    #[DataProvider('documentedResponses')]
    public function testTheApiProducesEveryDocumentedStatus(
        string $method,
        string $path,
        ?array $payload,
        array $headers,
        int $expected,
        bool $needsExistingItem,
    ): void {
        $http = HttpClient::fromEnvironment();
        $createdId = null;

        if ($needsExistingItem) {
            $created = $http->mutate('POST', '/api/items', ['name' => 'openapi-' . uniqid()]);
            self::assertSame(201, $created->status, $created->body);
            $createdId = (int) $created->json()['id'];
            $path = str_replace('{id}', (string) $createdId, $path);
        }

        try {
            $response = $headers === []
                ? $http->mutate($method, $path, $payload)
                : $http->json($method, $path, $payload, $headers);

            self::assertSame(
                $expected,
                $response->status,
                sprintf('%s %s should return %d: %s', $method, $path, $expected, $response->body),
            );

            $this->assertBodyMatchesTheDocumentedSchema($expected, $response->body);
        } finally {
            if ($createdId !== null) {
                $http->mutate('DELETE', '/api/items/' . $createdId);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null, array<string, string>, int, bool}>
     */
    public static function documentedResponses(): iterable
    {
        yield 'GET /api/items 200' => ['GET', '/api/items', null, [], 200, false];

        yield 'POST /api/items 201' => ['POST', '/api/items', ['name' => 'openapi-created'], [], 201, false];
        yield 'POST /api/items 403' => ['POST', '/api/items', ['name' => 'x'], ['X-CSRF-Token' => 'invalid'], 403, false];
        yield 'POST /api/items 422' => ['POST', '/api/items', ['name' => ''], [], 422, false];

        yield 'PUT /api/items/{id} 200' => ['PUT', '/api/items/{id}', ['name' => 'openapi-updated'], [], 200, true];
        yield 'PUT /api/items/{id} 403' => ['PUT', '/api/items/{id}', ['name' => 'x'], ['X-CSRF-Token' => 'invalid'], 403, true];
        yield 'PUT /api/items/{id} 404' => ['PUT', '/api/items/2147483000', ['name' => 'x'], [], 404, false];
        yield 'PUT /api/items/{id} 422' => ['PUT', '/api/items/{id}', ['name' => '   '], [], 422, true];

        yield 'DELETE /api/items/{id} 204' => ['DELETE', '/api/items/{id}', null, [], 204, true];
        yield 'DELETE /api/items/{id} 403' => ['DELETE', '/api/items/{id}', null, ['X-CSRF-Token' => 'invalid'], 403, true];
        yield 'DELETE /api/items/{id} 404' => ['DELETE', '/api/items/2147483000', null, [], 404, false];
    }

    /**
     * Checks the response body against the schema the document declares for that status:
     * `Item` for 200/201, `Error` for 4xx, nothing at all for 204.
     */
    private function assertBodyMatchesTheDocumentedSchema(int $status, string $body): void
    {
        if ($status === 204) {
            self::assertSame('', trim($body), 'A 204 must carry no body.');

            return;
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        if ($status < 300) {
            if (\array_key_exists('items', $decoded)) {
                foreach ($decoded['items'] as $item) {
                    $this->assertMatchesItemSchema($item);
                }

                return;
            }

            $this->assertMatchesItemSchema($decoded);

            return;
        }

        // components/schemas/Error: required [code, message], additionalProperties false.
        self::assertArrayHasKey('code', $decoded);
        self::assertArrayHasKey('message', $decoded);
        self::assertIsString($decoded['code']);
        self::assertIsString($decoded['message']);
        self::assertSame([], array_diff(array_keys($decoded), ['code', 'message', 'details']));

        if (\array_key_exists('details', $decoded)) {
            foreach ($decoded['details'] as $messages) {
                self::assertIsArray($messages);

                foreach ($messages as $message) {
                    self::assertIsString($message);
                }
            }
        }
    }

    /**
     * @param mixed $item
     */
    private function assertMatchesItemSchema(mixed $item): void
    {
        $schema = self::spec()['components']['schemas']['Item'];

        self::assertIsArray($item);
        self::assertSame($schema['required'], array_keys($item), 'Item has exactly the required properties.');
        self::assertIsInt($item['id']);
        self::assertGreaterThanOrEqual($schema['properties']['id']['minimum'], $item['id']);
        self::assertIsString($item['name']);
        self::assertGreaterThanOrEqual($schema['properties']['name']['minLength'], mb_strlen($item['name']));
        self::assertLessThanOrEqual($schema['properties']['name']['maxLength'], mb_strlen($item['name']));
    }
}
