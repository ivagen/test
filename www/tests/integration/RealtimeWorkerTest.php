<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\tests\Support\HttpClient;
use app\tests\Support\WebSocketProbe;
use PHPUnit\Framework\TestCase;
use yii\redis\Connection;

/**
 * The real-time worker as a black box (tasks.md T039/T041).
 *
 * Every assertion here goes through nginx at /ws, so a passing run also proves the
 * reverse-proxy Upgrade configuration and the same-origin routing are correct -- not only
 * that Workerman is running.
 *
 * Redis-outage recovery and SIGTERM shutdown need control over the containers themselves
 * and are therefore asserted from the host by scripts/acceptance.sh, which reports its
 * results into the same verification run.
 */
final class RealtimeWorkerTest extends TestCase
{
    private HttpClient $http;
    private WebSocketProbe $socket;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = HttpClient::fromEnvironment();
        $this->socket = WebSocketProbe::connect();
    }

    protected function tearDown(): void
    {
        $this->socket->close();

        foreach ($this->created as $id) {
            $this->http->mutate('DELETE', '/api/items/' . $id);
        }

        $this->created = [];

        parent::tearDown();
    }

    private function redis(): Connection
    {
        return new Connection([
            'hostname' => getenv('REDIS_HOST') ?: 'redis',
            'port' => (int) (getenv('REDIS_PORT') ?: '6379'),
            'database' => (int) (getenv('REDIS_DB') ?: '0'),
        ]);
    }

    private function channel(): string
    {
        return getenv('REALTIME_CHANNEL') ?: 'items.events';
    }

    /**
     * The handshake itself: a browser can upgrade at /ws on the page's own origin.
     */
    public function testTheProxyUpgradesTheConnectionAtSlashWs(): void
    {
        $probe = WebSocketProbe::connect();

        $probe->send('ping');

        self::assertSame('pong', $probe->receive(5.0), 'The worker must answer the liveness probe.');

        $probe->close();
    }

    public function testACommittedMutationReachesAConnectedClient(): void
    {
        $name = 'ws-create-' . uniqid();

        $response = $this->http->mutate('POST', '/api/items', ['name' => $name]);
        self::assertSame(201, $response->status, $response->body);

        $id = (int) $response->json()['id'];
        $this->created[] = $id;

        $event = $this->socket->receiveEvent(5.0);

        self::assertNotNull($event, 'A connected client must receive the event.');
        self::assertSame('item.created', $event['type']);
        self::assertSame($id, $event['itemId']);
        self::assertSame($name, $event['item']['name']);
    }

    /**
     * The worker relays the published bytes unchanged; it is not a place where an event
     * can be reinterpreted.
     */
    public function testTheWorkerRelaysThePayloadVerbatim(): void
    {
        $payload = json_encode([
            'eventId' => 'a0000000-0000-4000-8000-000000000001',
            'type' => 'item.updated',
            'itemId' => 4242,
            'item' => ['id' => 4242, 'name' => 'Хліб ☕ verbatim'],
            'occurredAt' => '2026-07-25T15:00:00.000Z',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->redis()->executeCommand('PUBLISH', [$this->channel(), $payload]);

        $received = $this->socket->receive(5.0);

        self::assertSame($payload, $received, 'The relayed frame must be byte-identical to what was published.');
    }

    /**
     * A malformed internal message must not take the worker down and disconnect every
     * browser. It must survive and keep delivering.
     */
    public function testMalformedInternalMessagesDoNotKillTheWorker(): void
    {
        foreach (['not json at all', '{"partial": ', '[]', '', str_repeat('x', 5000)] as $garbage) {
            $this->redis()->executeCommand('PUBLISH', [$this->channel(), $garbage]);
        }

        // Drain whatever the worker chose to relay; the contract lets the CLIENT decide
        // what to do with an unreadable frame, so relaying it is acceptable behaviour.
        $this->socket->receive(1.0);

        $name = 'ws-after-garbage-' . uniqid();
        $id = (int) $this->http->mutate('POST', '/api/items', ['name' => $name])->json()['id'];
        $this->created[] = $id;

        $event = null;
        $deadline = microtime(true) + 6.0;

        while (microtime(true) < $deadline) {
            $candidate = $this->socket->receiveEvent(2.0);

            if ($candidate !== null && ($candidate['itemId'] ?? null) === $id) {
                $event = $candidate;

                break;
            }
        }

        self::assertNotNull($event, 'The worker must keep delivering after malformed input.');
        self::assertSame('item.created', $event['type']);
    }

    /**
     * Publishing on an unrelated channel must not leak into this application's clients.
     */
    public function testEventsOnOtherChannelsAreNotDelivered(): void
    {
        $this->redis()->executeCommand('PUBLISH', ['some.other.channel', '{"type":"item.created"}']);

        self::assertNull($this->socket->receive(1.5), 'Only the configured channel may be relayed.');
    }

    /**
     * Every connected client receives the same event -- this is the cross-session
     * propagation spec US3 is built on, asserted here at the protocol level and again in
     * the browser by the Playwright suite.
     */
    public function testEveryConnectedClientReceivesTheSameEvent(): void
    {
        $second = WebSocketProbe::connect();

        try {
            $name = 'ws-fanout-' . uniqid();
            $id = (int) $this->http->mutate('POST', '/api/items', ['name' => $name])->json()['id'];
            $this->created[] = $id;

            $first = $this->socket->receiveEvent(5.0);
            $other = $second->receiveEvent(5.0);

            self::assertNotNull($first);
            self::assertNotNull($other);
            self::assertSame($first['eventId'], $other['eventId'], 'Both clients must see the same event.');
        } finally {
            $second->close();
        }
    }

    /**
     * The socket is one-way for application data: a client cannot mutate anything through
     * it, so an unexpected frame is simply ignored.
     */
    public function testClientFramesCannotMutateState(): void
    {
        $before = $this->http->json('GET', '/api/items')->json()['items'];

        $this->socket->send(json_encode(['type' => 'item.created', 'item' => ['id' => 1, 'name' => 'injected']]));
        $this->socket->send('DELETE /api/items/1');

        usleep(500_000);

        $after = $this->http->json('GET', '/api/items')->json()['items'];

        self::assertSame($before, $after, 'Nothing sent over the socket may change stored state.');
    }
}
