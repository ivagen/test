<?php

declare(strict_types=1);

namespace app\services;

use app\models\Items;

/**
 * A transient real-time notification, exactly as specified in
 * contracts/websocket-events.md and data-model.md.
 *
 * An event is a HINT, never a source of truth. Delivery may duplicate, reorder or drop
 * messages, and the client resolves any ambiguity by refetching GET /api/items. That is
 * why there is no sequence number, no persistence and no delivery guarantee here.
 */
final class ItemEvent implements \JsonSerializable
{
    public const TYPE_CREATED = 'item.created';
    public const TYPE_UPDATED = 'item.updated';
    public const TYPE_DELETED = 'item.deleted';

    /**
     * @param array{id: int, name: string}|null $item
     */
    private function __construct(
        private readonly string $eventId,
        private readonly string $type,
        private readonly int $itemId,
        private readonly ?array $item,
        private readonly string $occurredAt,
    ) {
    }

    public static function created(Items $item): self
    {
        return self::forItem(self::TYPE_CREATED, $item);
    }

    public static function updated(Items $item): self
    {
        return self::forItem(self::TYPE_UPDATED, $item);
    }

    /**
     * The row is gone, so there is no representation to send -- `item` is null and the
     * client removes `itemId` from its list.
     */
    public static function deleted(int $itemId): self
    {
        return new self(self::uuidV4(), self::TYPE_DELETED, $itemId, null, self::now());
    }

    private static function forItem(string $type, Items $item): self
    {
        return new self(self::uuidV4(), $type, (int) $item->id, $item->toApiRepresentation(), self::now());
    }

    /**
     * @return array{eventId: string, type: string, itemId: int, item: array{id: int, name: string}|null, occurredAt: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'eventId' => $this->eventId,
            'type' => $this->type,
            'itemId' => $this->itemId,
            'item' => $this->item,
            'occurredAt' => $this->occurredAt,
        ];
    }

    /**
     * The worker forwards this string to browsers verbatim, so it must be valid UTF-8 JSON
     * with real characters rather than \uXXXX escapes.
     */
    public function encode(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * RFC 3339 UTC with millisecond precision, as the contract requires.
     */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * A random (version 4) UUID from a cryptographically secure source.
     *
     * Written inline rather than pulling in ramsey/uuid: this is the only place the
     * project needs a UUID, and Constitution V asks for a minimal dependency count.
     */
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);

        // Set the version to 4 and the variant to RFC 4122.
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
