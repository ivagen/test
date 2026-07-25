<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\tests\Support\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for every response class in contracts/openapi.yaml (tasks.md T029,
 * FR-002, SC-003).
 *
 * Each test states which part of the contract it pins. Rows created here are deleted in
 * tearDown, so the suite is safe to run repeatedly against a database that also holds real
 * items.
 */
final class ApiItemsTest extends TestCase
{
    private HttpClient $http;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = HttpClient::fromEnvironment();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->http->mutate('DELETE', '/api/items/' . $id);
        }

        $this->created = [];

        parent::tearDown();
    }

    private function createItem(string $name): int
    {
        $response = $this->http->mutate('POST', '/api/items', ['name' => $name]);

        self::assertSame(201, $response->status, 'Fixture creation failed: ' . $response->body);

        $id = (int) $response->json()['id'];
        $this->created[] = $id;

        return $id;
    }

    // -- GET /api/items ------------------------------------------------------------------

    public function testListReturns200WithAnItemsEnvelope(): void
    {
        $response = $this->http->json('GET', '/api/items');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('application/json', (string) $response->header('Content-Type'));

        $body = $response->json();

        self::assertArrayHasKey('items', $body, 'The envelope key is `items` (the 2017 API used `rows`).');
        self::assertIsArray($body['items']);
    }

    public function testListIsOrderedByAscendingIdAndMatchesTheItemSchema(): void
    {
        $first = $this->createItem('order-check-a-' . uniqid());
        $second = $this->createItem('order-check-b-' . uniqid());

        $items = $this->http->json('GET', '/api/items')->json()['items'];

        $ids = array_column($items, 'id');
        $sorted = $ids;
        sort($sorted);

        self::assertSame($sorted, $ids, 'Items must be ordered by ascending id (data-model.md).');
        self::assertContains($first, $ids);
        self::assertContains($second, $ids);

        foreach ($items as $item) {
            // OpenAPI Item: additionalProperties false, required [id, name].
            self::assertSame(['id', 'name'], array_keys($item));
            self::assertIsInt($item['id']);
            self::assertIsString($item['name']);
        }
    }

    /**
     * A read is a safe method: it must work with no CSRF token at all.
     */
    public function testListRequiresNoCsrfToken(): void
    {
        $anonymous = HttpClient::fromEnvironment();

        self::assertSame(200, $anonymous->json('GET', '/api/items')->status);
    }

    // -- POST /api/items -----------------------------------------------------------------

    public function testCreateReturns201AndTheCreatedRepresentation(): void
    {
        $name = 'create-' . uniqid();

        $response = $this->http->mutate('POST', '/api/items', ['name' => $name]);

        self::assertSame(201, $response->status);

        $body = $response->json();
        $this->created[] = (int) $body['id'];

        self::assertSame(['id', 'name'], array_keys($body));
        self::assertIsInt($body['id']);
        self::assertGreaterThanOrEqual(1, $body['id']);
        self::assertSame($name, $body['name']);
    }

    /**
     * Spec US2 scenario 2: the item is stored ONCE.
     */
    public function testCreateStoresExactlyOneItem(): void
    {
        $name = 'once-' . uniqid();

        $this->createItem($name);

        $items = $this->http->json('GET', '/api/items')->json()['items'];
        $matches = array_filter($items, static fn (array $item): bool => $item['name'] === $name);

        self::assertCount(1, $matches);
    }

    public function testCreateTrimsTheName(): void
    {
        $name = 'trimmed-' . uniqid();

        $response = $this->http->mutate('POST', '/api/items', ['name' => '   ' . $name . "\t"]);

        self::assertSame(201, $response->status);

        $body = $response->json();
        $this->created[] = (int) $body['id'];

        self::assertSame($name, $body['name']);
    }

    public function testCreateAcceptsUnicodeAndThe255CharacterBoundary(): void
    {
        $response = $this->http->mutate('POST', '/api/items', ['name' => str_repeat('ä', 255)]);

        self::assertSame(201, $response->status, $response->body);

        $body = $response->json();
        $this->created[] = (int) $body['id'];

        self::assertSame(255, mb_strlen($body['name']));
    }

    // -- 422 -----------------------------------------------------------------------------

    /**
     * Spec US2 scenario 5 and quickstart.md's smoke test: 422 with `code`, `message` AND
     * `details`.
     *
     * @param mixed $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidInputReturns422WithAStableErrorBody(mixed $payload): void
    {
        $response = $this->http->mutate('POST', '/api/items', $payload);

        self::assertSame(422, $response->status, 'Payload was accepted: ' . $response->body);

        $body = $response->json();

        self::assertSame('validation_failed', $body['code']);
        self::assertIsString($body['message']);
        self::assertArrayHasKey('details', $body);
        self::assertIsArray($body['details']);
        self::assertNotSame([], $body['details']);

        foreach ($body['details'] as $field => $messages) {
            self::assertIsString($field);
            self::assertIsArray($messages);

            foreach ($messages as $message) {
                self::assertIsString($message);
                // FR-010: no trace, path or SQL may leak through an error body.
                self::assertStringNotContainsString('/var/www', $message);
                self::assertStringNotContainsString('SELECT', $message);
            }
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'missing name' => [[]];
        yield 'null name' => [['name' => null]];
        yield 'empty name' => [['name' => '']];
        yield 'whitespace only' => [['name' => '   ']];
        yield 'tab and newline only' => [['name' => "\t\n"]];
        yield '256 characters' => [['name' => str_repeat('a', 256)]];
        yield '256 unicode characters' => [['name' => str_repeat('ä', 256)]];
        yield 'name is a number' => [['name' => 42]];
        yield 'name is an array' => [['name' => ['nested']]];
        yield 'unknown property' => [['name' => 'ok', 'id' => 5]];
    }

    /**
     * Trimming happens before the length check, so 255 characters wrapped in spaces is
     * accepted rather than treated as a 257-character overflow.
     */
    public function testWhitespacePaddedMaximumLengthIsAccepted(): void
    {
        $response = $this->http->mutate('POST', '/api/items', ['name' => ' ' . str_repeat('a', 255) . ' ']);

        self::assertSame(201, $response->status, $response->body);
        $this->created[] = (int) $response->json()['id'];
    }

    public function testMalformedJsonIsRejected(): void
    {
        $response = $this->http->request('POST', '/api/items', '{"name": ', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-CSRF-Token' => $this->http->csrfToken(),
        ]);

        self::assertContains($response->status, [400, 422], 'Malformed JSON must be rejected: ' . $response->body);

        $body = $response->json();
        self::assertArrayHasKey('code', $body);
        self::assertArrayHasKey('message', $body);
    }

    // -- 415 -----------------------------------------------------------------------------

    #[DataProvider('nonJsonContentTypes')]
    public function testNonJsonRequestBodyReturns415(string $contentType, string $body): void
    {
        $response = $this->http->request('POST', '/api/items', $body, [
            'Content-Type' => $contentType,
            'Accept' => 'application/json',
            'X-CSRF-Token' => $this->http->csrfToken(),
        ]);

        self::assertSame(415, $response->status, 'Expected 415 for ' . $contentType . ': ' . $response->body);
        self::assertSame('unsupported_media_type', $response->json()['code']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonJsonContentTypes(): iterable
    {
        // This is exactly what the 2017 AngularJS client sent.
        yield 'form urlencoded' => ['application/x-www-form-urlencoded', 'Items[name]=Milk'];
        yield 'plain text' => ['text/plain', 'Milk'];
        yield 'xml' => ['application/xml', '<item><name>Milk</name></item>'];
    }

    // -- 403 (CSRF) ----------------------------------------------------------------------

    /**
     * FR-010 and quickstart.md item 7. The 2017 controller set
     * `$enableCsrfValidation = false`, so every mutation was cross-site forgeable.
     */
    public function testMutationWithoutCsrfTokenReturns403(): void
    {
        $response = $this->http->json('POST', '/api/items', ['name' => 'no-csrf']);

        self::assertSame(403, $response->status, $response->body);
        self::assertSame('forbidden', $response->json()['code']);
    }

    public function testMutationWithInvalidCsrfTokenReturns403(): void
    {
        $response = $this->http->json('POST', '/api/items', ['name' => 'bad-csrf'], [
            'X-CSRF-Token' => 'definitely-not-a-valid-token',
        ]);

        self::assertSame(403, $response->status, $response->body);
        self::assertSame('forbidden', $response->json()['code']);
    }

    public function testUpdateAndDeleteAlsoRequireCsrf(): void
    {
        $id = $this->createItem('csrf-target-' . uniqid());

        self::assertSame(403, $this->http->json('PUT', '/api/items/' . $id, ['name' => 'x'])->status);
        self::assertSame(403, $this->http->json('DELETE', '/api/items/' . $id)->status);
    }

    /**
     * A rejected mutation must change nothing.
     */
    public function testRejectedCsrfMutationDoesNotPersist(): void
    {
        $name = 'never-stored-' . uniqid();

        $this->http->json('POST', '/api/items', ['name' => $name]);

        $items = $this->http->json('GET', '/api/items')->json()['items'];

        self::assertSame([], array_filter($items, static fn (array $i): bool => $i['name'] === $name));
    }

    // -- PUT /api/items/{id} -------------------------------------------------------------

    public function testUpdateReturns200WithTheSameIdAndTheNewName(): void
    {
        $id = $this->createItem('before-' . uniqid());
        $newName = 'after-' . uniqid();

        $response = $this->http->mutate('PUT', '/api/items/' . $id, ['name' => $newName]);

        self::assertSame(200, $response->status, $response->body);

        $body = $response->json();

        self::assertSame(['id', 'name'], array_keys($body));
        self::assertSame($id, $body['id']);
        self::assertSame($newName, $body['name']);
    }

    /**
     * Spec US2 scenario 3: "later reads show it".
     */
    public function testUpdateIsVisibleToSubsequentReads(): void
    {
        $id = $this->createItem('stale-' . uniqid());
        $newName = 'fresh-' . uniqid();

        $this->http->mutate('PUT', '/api/items/' . $id, ['name' => $newName]);

        $items = $this->http->json('GET', '/api/items')->json()['items'];
        $match = array_values(array_filter($items, static fn (array $i): bool => $i['id'] === $id));

        self::assertCount(1, $match);
        self::assertSame($newName, $match[0]['name']);
    }

    public function testUpdateWithInvalidNameReturns422AndDoesNotChangeTheItem(): void
    {
        $original = 'unchanged-' . uniqid();
        $id = $this->createItem($original);

        $response = $this->http->mutate('PUT', '/api/items/' . $id, ['name' => '   ']);

        self::assertSame(422, $response->status, $response->body);

        $items = $this->http->json('GET', '/api/items')->json()['items'];
        $match = array_values(array_filter($items, static fn (array $i): bool => $i['id'] === $id));

        self::assertSame($original, $match[0]['name']);
    }

    // -- DELETE /api/items/{id} ----------------------------------------------------------

    public function testDeleteReturns204WithAnEmptyBody(): void
    {
        $id = $this->createItem('doomed-' . uniqid());

        $response = $this->http->mutate('DELETE', '/api/items/' . $id);

        self::assertSame(204, $response->status);
        self::assertSame('', trim($response->body), 'A 204 response must have no body.');

        $items = $this->http->json('GET', '/api/items')->json()['items'];

        self::assertNotContains($id, array_column($items, 'id'));

        // Already removed; keep tearDown from reporting a spurious failure.
        $this->created = array_values(array_diff($this->created, [$id]));
    }

    // -- 404 -----------------------------------------------------------------------------

    /**
     * Spec US2 scenario 6: 404 "without exposing an exception or stack trace".
     */
    #[DataProvider('unknownIdRequests')]
    public function testUnknownIdReturns404WithoutLeakingInternals(string $method): void
    {
        $unknownId = 2147483000;

        $payload = $method === 'PUT' ? ['name' => 'ghost'] : null;
        $response = $this->http->mutate($method, '/api/items/' . $unknownId, $payload);

        self::assertSame(404, $response->status, $response->body);

        $body = $response->json();

        self::assertSame('not_found', $body['code']);
        self::assertIsString($body['message']);

        foreach (['Exception', 'Stack trace', '/var/www', 'yii\\', 'SELECT', 'pgsql'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $response->body);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIdRequests(): iterable
    {
        yield 'update' => ['PUT'];
        yield 'delete' => ['DELETE'];
    }

    /**
     * OpenAPI declares `id` as `integer, minimum: 1`, so neither 0 nor a non-numeric
     * segment may reach a controller.
     */
    #[DataProvider('invalidIdPaths')]
    public function testInvalidIdPathReturns404(string $path): void
    {
        $response = $this->http->mutate('DELETE', $path);

        self::assertSame(404, $response->status, 'Expected 404 for ' . $path . ': ' . $response->body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdPaths(): iterable
    {
        yield 'zero' => ['/api/items/0'];
        yield 'negative' => ['/api/items/-1'];
        yield 'not a number' => ['/api/items/abc'];
        yield 'float' => ['/api/items/1.5'];
    }

    /**
     * Concurrency (spec edge case): the second delete of the same id is a 404, not a 500.
     */
    public function testDeletingAnAlreadyDeletedItemReturns404(): void
    {
        $id = $this->createItem('double-delete-' . uniqid());

        self::assertSame(204, $this->http->mutate('DELETE', '/api/items/' . $id)->status);
        self::assertSame(404, $this->http->mutate('DELETE', '/api/items/' . $id)->status);

        $this->created = array_values(array_diff($this->created, [$id]));
    }
}
