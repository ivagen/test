<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\tests\Support\HttpClient;
use app\tests\Support\RedisProbe;
use PHPUnit\Framework\TestCase;

/**
 * Post-commit publication semantics, observed on the real Redis channel
 * (tasks.md T038/T042, FR-005, FR-007).
 *
 * These assert what actually reaches the wire after a real HTTP request, which is the only
 * way to prove "published only after the database operation commits" rather than merely
 * "the publisher class was called".
 */
final class RealtimeEventsTest extends TestCase
{
    private HttpClient $http;
    private RedisProbe $probe;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = HttpClient::fromEnvironment();
        $this->probe = RedisProbe::subscribe(getenv('REALTIME_CHANNEL') ?: 'items.events');
    }

    protected function tearDown(): void
    {
        $this->probe->close();

        foreach ($this->created as $id) {
            $this->http->mutate('DELETE', '/api/items/' . $id);
        }

        $this->created = [];

        parent::tearDown();
    }

    public function testCreatePublishesAnItemCreatedEventAfterTheCommit(): void
    {
        $name = 'event-create-' . uniqid();

        $response = $this->http->mutate('POST', '/api/items', ['name' => $name]);
        self::assertSame(201, $response->status, $response->body);

        $id = (int) $response->json()['id'];
        $this->created[] = $id;

        $event = $this->probe->nextEvent();

        self::assertNotNull($event, 'A successful create must publish an event.');
        self::assertSame('item.created', $event['type']);
        self::assertSame($id, $event['itemId']);
        self::assertSame(['id' => $id, 'name' => $name], $event['item']);

        // The envelope must match contracts/websocket-events.md exactly.
        self::assertSame(['eventId', 'type', 'itemId', 'item', 'occurredAt'], array_keys($event));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event['eventId'],
        );
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event['occurredAt']);
    }

    /**
     * The event announces state that a later read must agree with. Publishing before the
     * commit would let a client act on a change that never landed.
     */
    public function testThePublishedItemMatchesWhatTheApiSubsequentlyReturns(): void
    {
        $name = 'event-consistency-' . uniqid();

        $id = (int) $this->http->mutate('POST', '/api/items', ['name' => $name])->json()['id'];
        $this->created[] = $id;

        $event = $this->probe->nextEvent();
        self::assertNotNull($event);

        $items = $this->http->json('GET', '/api/items')->json()['items'];
        $stored = array_values(array_filter($items, static fn (array $item): bool => $item['id'] === $id));

        self::assertSame($stored[0], $event['item'], 'The event payload must equal the committed state.');
    }

    public function testUpdatePublishesItemUpdatedWithTheNewRepresentation(): void
    {
        $id = (int) $this->http->mutate('POST', '/api/items', ['name' => 'before-' . uniqid()])->json()['id'];
        $this->created[] = $id;
        $this->probe->nextEvent();

        $newName = 'after-' . uniqid();
        self::assertSame(200, $this->http->mutate('PUT', '/api/items/' . $id, ['name' => $newName])->status);

        $event = $this->probe->nextEvent();

        self::assertNotNull($event);
        self::assertSame('item.updated', $event['type']);
        self::assertSame(['id' => $id, 'name' => $newName], $event['item']);
    }

    /**
     * data-model.md: `item` is null for a delete, because the row no longer exists.
     */
    public function testDeletePublishesItemDeletedWithANullItem(): void
    {
        $id = (int) $this->http->mutate('POST', '/api/items', ['name' => 'doomed-' . uniqid()])->json()['id'];
        $this->probe->nextEvent();

        self::assertSame(204, $this->http->mutate('DELETE', '/api/items/' . $id)->status);

        $event = $this->probe->nextEvent();

        self::assertNotNull($event);
        self::assertSame('item.deleted', $event['type']);
        self::assertSame($id, $event['itemId']);
        self::assertNull($event['item']);
    }

    /**
     * FR-005: a rejected mutation changes nothing, so it must announce nothing.
     *
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedMutations')]
    public function testRejectedMutationsPublishNothing(string $method, string $path, ?array $payload): void
    {
        $response = $this->http->mutate($method, $path, $payload);

        self::assertGreaterThanOrEqual(400, $response->status, 'This request was supposed to fail.');
        self::assertTrue(
            $this->probe->expectSilence(1.5),
            'A failed mutation must not publish an event.',
        );
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function rejectedMutations(): iterable
    {
        yield 'create with a blank name' => ['POST', '/api/items', ['name' => '   ']];
        yield 'create that is too long' => ['POST', '/api/items', ['name' => str_repeat('a', 256)]];
        yield 'update of an unknown id' => ['PUT', '/api/items/2147483000', ['name' => 'ghost']];
        yield 'delete of an unknown id' => ['DELETE', '/api/items/2147483000', null];
    }

    /**
     * A CSRF-rejected request never reaches the model, so it must be silent too.
     */
    public function testCsrfRejectedMutationPublishesNothing(): void
    {
        $anonymous = HttpClient::fromEnvironment();

        self::assertSame(403, $anonymous->json('POST', '/api/items', ['name' => 'forged'])->status);
        self::assertTrue($this->probe->expectSilence(1.5));
    }

    /**
     * Exactly one event per mutation: a duplicate would be tolerated by the client, but it
     * would still mean the server is publishing twice for one change.
     */
    public function testExactlyOneEventIsPublishedPerMutation(): void
    {
        $id = (int) $this->http->mutate('POST', '/api/items', ['name' => 'single-' . uniqid()])->json()['id'];
        $this->created[] = $id;

        self::assertNotNull($this->probe->nextEvent());
        self::assertTrue($this->probe->expectSilence(1.0), 'A single create must publish a single event.');
    }
}
