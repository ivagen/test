<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\models\Items;
use app\services\ItemEvent;
use app\tests\DatabaseTestCase;

/**
 * The wire shape of a real-time event (tasks.md T036).
 *
 * Pins contracts/websocket-events.md exactly: five fields, a UUID `eventId` for client
 * de-duplication, RFC 3339 UTC `occurredAt`, and `item` present for create/update but null
 * for delete.
 */
final class ItemEventTest extends DatabaseTestCase
{
    private function persistedItem(string $name = 'Milk'): Items
    {
        $item = new Items(['name' => $name]);

        self::assertTrue($item->save(), implode('; ', $item->getFirstErrors()));

        return $item;
    }

    public function testCreatedEventCarriesTheItemRepresentation(): void
    {
        $item = $this->persistedItem('Created');

        $payload = ItemEvent::created($item)->jsonSerialize();

        self::assertSame('item.created', $payload['type']);
        self::assertSame($item->id, $payload['itemId']);
        self::assertSame(['id' => $item->id, 'name' => 'Created'], $payload['item']);
    }

    public function testUpdatedEventCarriesTheCurrentRepresentation(): void
    {
        $item = $this->persistedItem('Before');
        $item->name = 'After';

        self::assertTrue($item->save());

        $payload = ItemEvent::updated($item)->jsonSerialize();

        self::assertSame('item.updated', $payload['type']);
        self::assertSame(['id' => $item->id, 'name' => 'After'], $payload['item']);
    }

    /**
     * data-model.md: `item` is "null for delete" -- the row no longer exists, so there is
     * nothing truthful to send.
     */
    public function testDeletedEventHasANullItem(): void
    {
        $payload = ItemEvent::deleted(17)->jsonSerialize();

        self::assertSame('item.deleted', $payload['type']);
        self::assertSame(17, $payload['itemId']);
        self::assertNull($payload['item']);
    }

    public function testEnvelopeContainsExactlyTheContractedFields(): void
    {
        $payload = ItemEvent::deleted(1)->jsonSerialize();

        self::assertSame(['eventId', 'type', 'itemId', 'item', 'occurredAt'], array_keys($payload));
    }

    /**
     * De-duplication (contracts/websocket-events.md client rule 2) only works if every
     * publication gets a fresh id.
     */
    public function testEventIdIsAUniqueUuidV4(): void
    {
        $ids = [];

        for ($i = 0; $i < 200; $i++) {
            $id = ItemEvent::deleted(1)->jsonSerialize()['eventId'];

            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
                'eventId must be a v4 UUID.',
            );

            $ids[] = $id;
        }

        self::assertCount(200, array_unique($ids), 'Every event must have a unique id.');
    }

    public function testOccurredAtIsRfc3339UtcWithMilliseconds(): void
    {
        $occurredAt = ItemEvent::deleted(1)->jsonSerialize()['occurredAt'];

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $occurredAt,
            'occurredAt must be an RFC 3339 UTC timestamp with millisecond precision.',
        );

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $occurredAt, new \DateTimeZone('UTC'));

        self::assertInstanceOf(\DateTimeImmutable::class, $parsed);
        self::assertLessThan(60, abs($parsed->getTimestamp() - time()), 'The timestamp must be server-current.');
    }

    /**
     * The worker forwards the encoded string verbatim, so it must be valid, non-escaped
     * UTF-8 JSON.
     */
    public function testEncodesUnicodeWithoutEscaping(): void
    {
        $item = $this->persistedItem('Хліб ☕');

        $json = ItemEvent::created($item)->encode();

        self::assertStringContainsString('Хліб ☕', $json);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Хліб ☕', $decoded['item']['name']);
    }
}
