<?php

declare(strict_types=1);

namespace app\tests\legacy;

use app\tests\Support\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the 2017 application, asserted against the revived one
 * (tasks.md T004, Constitution I).
 *
 * WHY THESE TESTS LOOK LIKE THIS
 *
 * The original stack cannot be started: `docker build docker/front/` fails at
 * `apt-get install nodejs` because the retired NodeSource Node 6 repository no longer has
 * a verifiable signing key, and `ubuntu:16.04` publishes no arm64 image at all. The full
 * There is therefore no running legacy system to record behaviour from.
 *
 * Constitution I permits exactly this case: every replacement needs "an automated **or
 * documented** parity check". So the legacy contract was read out of the 2017 sources --
 * SiteController, models/Items, web/source/js/main.js and daemons/WebSocket.php, all of
 * which are preserved in this repository's git history -- and encoded below as executable
 * assertions against the new stack.
 *
 * Two kinds of test live here, and the difference is the point:
 *
 *  - PRESERVED: behaviour a user could observe in 2017 that must still hold today.
 *  - CHANGED:   behaviour the revival deliberately changes. Each one names the requirement
 *               that mandates the change, so nothing is quietly dropped.
 */
final class LegacyParityTest extends TestCase
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

    private function create(string $name): int
    {
        $response = $this->http->mutate('POST', '/api/items', ['name' => $name]);
        self::assertSame(201, $response->status, $response->body);

        $id = (int) $response->json()['id'];
        $this->created[] = $id;

        return $id;
    }

    // =======================================================================================
    // PRESERVED behaviour
    // =======================================================================================

    /**
     * Legacy: `SiteController::getItems()` ran `Items::find()->orderBy('id')->asArray()->all()`.
     * The list was always ordered by ascending id, and it is still.
     */
    public function testPreservedTheListIsOrderedByAscendingId(): void
    {
        $this->create('legacy-order-a-' . uniqid());
        $this->create('legacy-order-b-' . uniqid());

        $ids = array_column($this->http->json('GET', '/api/items')->json()['items'], 'id');
        $sorted = $ids;
        sort($sorted);

        self::assertSame($sorted, $ids);
    }

    /**
     * Legacy: each row exposed exactly `id` and `name` -- the table has only those columns.
     */
    public function testPreservedEachRowExposesOnlyIdAndName(): void
    {
        $this->create('legacy-shape-' . uniqid());

        foreach ($this->http->json('GET', '/api/items')->json()['items'] as $item) {
            self::assertSame(['id', 'name'], array_keys($item));
        }
    }

    /**
     * Legacy: `actionCreate` returned HTTP 201 and the new id.
     */
    public function testPreservedCreateReturns201AndTheNewId(): void
    {
        $response = $this->http->mutate('POST', '/api/items', ['name' => 'legacy-create-' . uniqid()]);

        self::assertSame(201, $response->status);

        $id = (int) $response->json()['id'];
        $this->created[] = $id;

        self::assertGreaterThan(0, $id);
    }

    /**
     * Legacy: `actionUpdate` returned HTTP 200 and kept the same id.
     */
    public function testPreservedUpdateReturns200AndKeepsTheId(): void
    {
        $id = $this->create('legacy-before-' . uniqid());
        $newName = 'legacy-after-' . uniqid();

        $response = $this->http->mutate('PUT', '/api/items/' . $id, ['name' => $newName]);

        self::assertSame(200, $response->status);
        self::assertSame($id, $response->json()['id']);
        self::assertSame($newName, $response->json()['name']);
    }

    /**
     * Legacy: `actionDelete` returned HTTP 204.
     */
    public function testPreservedDeleteReturns204(): void
    {
        $id = $this->create('legacy-delete-' . uniqid());

        self::assertSame(204, $this->http->mutate('DELETE', '/api/items/' . $id)->status);

        $this->created = array_values(array_diff($this->created, [$id]));
    }

    /**
     * Legacy: an unknown id threw NotFoundHttpException, caught and answered with HTTP 404.
     */
    public function testPreservedUnknownIdReturns404(): void
    {
        self::assertSame(404, $this->http->mutate('DELETE', '/api/items/2147483000')->status);
        self::assertSame(404, $this->http->mutate('PUT', '/api/items/2147483000', ['name' => 'x'])->status);
    }

    /**
     * Legacy: `rules()` had `[['name'], 'required']`, so a blank name was rejected and no
     * row was written.
     */
    public function testPreservedABlankNameIsRejectedAndStoresNothing(): void
    {
        $before = \count($this->http->json('GET', '/api/items')->json()['items']);

        $response = $this->http->mutate('POST', '/api/items', ['name' => '']);

        self::assertGreaterThanOrEqual(400, $response->status);
        self::assertCount($before, $this->http->json('GET', '/api/items')->json()['items']);
    }

    /**
     * Legacy: `[['name'], 'string', 'max' => 255]` against a `varchar(255)` column.
     */
    public function testPreservedThe255CharacterLimitStillApplies(): void
    {
        $ok = $this->http->mutate('POST', '/api/items', ['name' => str_repeat('a', 255)]);
        self::assertSame(201, $ok->status);
        $this->created[] = (int) $ok->json()['id'];

        self::assertGreaterThanOrEqual(400, $this->http->mutate('POST', '/api/items', ['name' => str_repeat('a', 256)])->status);
    }

    /**
     * Legacy: names were stored in a UTF-8 PostgreSQL column and rendered as-is.
     */
    public function testPreservedUnicodeNamesRoundTripUnchanged(): void
    {
        $name = 'Хліб ☕ 日本語 🍰 ' . uniqid();

        $id = $this->create($name);

        $items = $this->http->json('GET', '/api/items')->json()['items'];
        $match = array_values(array_filter($items, static fn (array $i): bool => $i['id'] === $id));

        self::assertSame($name, $match[0]['name']);
    }

    /**
     * Legacy: the daemon pushed the current list to every connected client, so a change in
     * one session appeared in another without a refresh. The mechanism changed completely;
     * the observable behaviour must not have. Asserted end-to-end in
     * frontend/e2e/realtime.spec.ts and at the protocol level in RealtimeWorkerTest.
     */
    public function testPreservedAChangeIsAnnouncedToOtherSessions(): void
    {
        $probe = \app\tests\Support\WebSocketProbe::connect();

        try {
            $id = $this->create('legacy-realtime-' . uniqid());

            $event = $probe->receiveEvent(5.0);

            self::assertNotNull($event, 'Another session must be told about the change.');
            self::assertSame($id, $event['itemId']);
        } finally {
            $probe->close();
        }
    }

    // =======================================================================================
    // CHANGED behaviour -- each deviation is deliberate and traceable
    // =======================================================================================

    /**
     * CHANGED, by FR-002 and research.md: the query routes `index.php?r=site/*` are gone,
     * replaced by the resource paths in contracts/openapi.yaml.
     *
     * Decision (T034/FR-015): removed rather than kept behind a deprecated adapter. They
     * held no business logic of their own, have no external consumer, and every one of them
     * was a CSRF-exempt mutation endpoint. The migration path is documented in README.md.
     */
    public function testChangedLegacyQueryRoutesNoLongerPerformActions(): void
    {
        $before = $this->http->json('GET', '/api/items')->json()['items'];

        foreach (['site/get', 'site/create', 'site/update&id=1', 'site/delete&id=1'] as $route) {
            $response = $this->http->request('GET', '/index.php?r=' . $route);

            self::assertNotSame(200, $response->status, $route . ' still resolves.');
        }

        self::assertSame($before, $this->http->json('GET', '/api/items')->json()['items']);
    }

    /**
     * CHANGED, by FR-002: the envelope key is `items`, not the legacy `rows`.
     */
    public function testChangedTheListEnvelopeKeyIsItemsNotRows(): void
    {
        $body = $this->http->json('GET', '/api/items')->json();

        self::assertArrayHasKey('items', $body);
        self::assertArrayNotHasKey('rows', $body);
    }

    /**
     * CHANGED, by FR-010: the 2017 controller set `$enableCsrfValidation = false`, so every
     * mutation was cross-site forgeable. Mutations now require the token.
     */
    public function testChangedMutationsNowRequireCsrf(): void
    {
        self::assertSame(403, HttpClient::fromEnvironment()->json('POST', '/api/items', ['name' => 'forged'])->status);
    }

    /**
     * CHANGED, by FR-002/FR-003: a validation failure was HTTP 400 with
     * `{"error": {...}}`; it is now HTTP 422 with the stable `{code, message, details}`
     * envelope from contracts/openapi.yaml.
     */
    public function testChangedValidationFailuresAre422WithAStableEnvelope(): void
    {
        $response = $this->http->mutate('POST', '/api/items', ['name' => '']);

        self::assertSame(422, $response->status);

        $body = $response->json();

        self::assertSame('validation_failed', $body['code']);
        self::assertArrayNotHasKey('error', $body);
    }

    /**
     * CHANGED, by FR-002: the client sent `application/x-www-form-urlencoded` with
     * `Items[name]=...`. The API is JSON-only and says so with 415.
     */
    public function testChangedFormEncodedBodiesAreRejected(): void
    {
        $response = $this->http->request('POST', '/api/items', 'Items%5Bname%5D=Milk', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-CSRF-Token' => $this->http->csrfToken(),
        ]);

        self::assertSame(415, $response->status);
    }

    /**
     * CHANGED, by FR-003: the legacy rules never trimmed, so "   " passed `required` in
     * some cases and a padded name was stored verbatim.
     */
    public function testChangedNamesAreTrimmedAndWhitespaceOnlyIsRejected(): void
    {
        self::assertSame(422, $this->http->mutate('POST', '/api/items', ['name' => '   '])->status);

        $name = 'legacy-trim-' . uniqid();
        $response = $this->http->mutate('POST', '/api/items', ['name' => '  ' . $name . '  ']);

        self::assertSame(201, $response->status);
        $this->created[] = (int) $response->json()['id'];
        self::assertSame($name, $response->json()['name']);
    }

    /**
     * CHANGED, by Constitution IV: the real-time endpoint was a raw WebSocket on host port
     * 8047 -- a second origin that could never be secured. It is now same-origin at /ws.
     */
    public function testChangedRealtimeIsSameOriginAtSlashWs(): void
    {
        $probe = \app\tests\Support\WebSocketProbe::connect();

        try {
            $probe->send('ping');
            self::assertSame('pong', $probe->receive(5.0));
        } finally {
            $probe->close();
        }

        // The legacy path on the public origin is gone.
        self::assertNotSame(101, $this->http->request('GET', '/websocket')->status);
    }
}
