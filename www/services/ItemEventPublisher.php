<?php

declare(strict_types=1);

namespace app\services;

use yii\redis\Connection;

/**
 * Publishes committed item changes to Redis Pub/Sub for the real-time worker to fan out.
 *
 * Two rules define this class, both from FR-005 and FR-007:
 *
 *  1. It is only ever called AFTER the database transaction has committed. An event that
 *     announced a change which was later rolled back would be a lie the client could not
 *     detect.
 *  2. It never throws. By the time it runs the user's data is already safely stored, so a
 *     Redis outage must degrade real-time delivery only -- turning it into a failed HTTP
 *     response would trade a durable success for a cosmetic one. Connected clients recover
 *     on their own by refetching GET /api/items.
 */
final class ItemEventPublisher
{
    public const LOG_CATEGORY = 'app\services\ItemEventPublisher';

    public function __construct(
        private readonly Connection $redis,
        private readonly string $channel,
    ) {
    }

    public static function fromApplication(): self
    {
        /** @var Connection $redis */
        $redis = \Yii::$app->get('redis');

        return new self($redis, (string) \Yii::$app->params['realtime.channel']);
    }

    /**
     * @return bool true when the event was handed to Redis; false when it was dropped
     */
    public function publish(ItemEvent $event): bool
    {
        try {
            $this->redis->executeCommand('PUBLISH', [$this->channel, $event->encode()]);

            return true;
        } catch (\Throwable $exception) {
            // Deliberately swallowed: see rule 2 above. Logged at warning level because a
            // silent drop is exactly the kind of degradation an operator needs to see.
            \Yii::warning(
                sprintf(
                    'Real-time event was not published (clients will resynchronise over HTTP): %s: %s',
                    $exception::class,
                    $exception->getMessage(),
                ),
                self::LOG_CATEGORY,
            );

            return false;
        }
    }
}
