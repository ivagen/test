<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\services\ItemEvent;
use app\services\ItemEventPublisher;
use app\tests\DatabaseTestCase;
use yii\redis\Connection;

/**
 * Publication is best effort and must never break a committed mutation
 * (tasks.md T036/T038, FR-005, FR-007).
 */
final class ItemEventPublisherTest extends DatabaseTestCase
{
    public function testPublishesTheEncodedEventToTheConfiguredChannel(): void
    {
        $redis = new RecordingRedisConnection();
        $publisher = new ItemEventPublisher($redis, 'test.channel');

        $event = ItemEvent::deleted(9);

        self::assertTrue($publisher->publish($event));
        self::assertCount(1, $redis->commands);

        [$name, $parameters] = $redis->commands[0];

        self::assertSame('PUBLISH', $name);
        self::assertSame('test.channel', $parameters[0]);

        $decoded = json_decode($parameters[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('item.deleted', $decoded['type']);
        self::assertSame(9, $decoded['itemId']);
    }

    /**
     * FR-007: "A real-time outage MUST NOT prevent valid CRUD operations." The mutation has
     * already committed by the time publish() is called, so a Redis failure may only be
     * reported -- never rethrown.
     */
    public function testReturnsFalseInsteadOfThrowingWhenRedisFails(): void
    {
        $publisher = new ItemEventPublisher(new FailingRedisConnection(), 'test.channel');

        self::assertFalse($publisher->publish(ItemEvent::deleted(1)), 'A Redis failure must be reported, not thrown.');
    }

    public function testAFailedPublicationIsLoggedAsADegradedSignal(): void
    {
        $logger = \Yii::getLogger();
        $before = \count($logger->messages);

        (new ItemEventPublisher(new FailingRedisConnection(), 'test.channel'))->publish(ItemEvent::deleted(1));

        $messages = \array_slice($logger->messages, $before);

        self::assertNotSame([], $messages, 'A dropped event must leave an operator-visible trace.');

        $categories = array_column($messages, 2);
        self::assertContains(ItemEventPublisher::LOG_CATEGORY, $categories);
    }
}

/**
 * Captures commands instead of talking to Redis.
 */
final class RecordingRedisConnection extends Connection
{
    /** @var list<array{string, array<int, mixed>}> */
    public array $commands = [];

    /**
     * @param string           $name
     * @param array<int,mixed> $params
     *
     * @return array<int,mixed>|bool|string|null
     */
    public function executeCommand($name, $params = [])
    {
        $this->commands[] = [$name, $params];

        return true;
    }
}

/**
 * Simulates Redis being down, which is the case FR-007 is about.
 */
final class FailingRedisConnection extends Connection
{
    /**
     * @param string           $name
     * @param array<int,mixed> $params
     *
     * @return array<int,mixed>|bool|string|null
     */
    public function executeCommand($name, $params = [])
    {
        throw new \yii\db\Exception('Redis is unavailable');
    }
}
