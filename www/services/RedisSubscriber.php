<?php

declare(strict_types=1);

namespace app\services;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Redis\Protocols\Redis as RedisProtocol;
use Workerman\Timer;

/**
 * A non-blocking Redis Pub/Sub subscriber for the real-time worker.
 *
 * Why not a plain client library: the worker is a single-threaded event loop that also
 * serves WebSocket frames. A blocking `SUBSCRIBE` would park that loop forever and the
 * worker would stop answering clients entirely.
 *
 * Why not workerman/redis's own Client: it reconnects after a dropped connection but does
 * NOT re-issue SUBSCRIBE, so a Redis restart would leave a live socket that silently
 * receives nothing ever again -- the worst kind of outage, because everything still looks
 * healthy. This class owns the whole lifecycle instead, re-subscribing on every connect,
 * and reuses only workerman/redis's RESP protocol codec.
 */
final class RedisSubscriber
{
    private const BASE_BACKOFF_SECONDS = 0.5;
    private const MAX_BACKOFF_SECONDS = 10.0;

    private ?AsyncTcpConnection $connection = null;
    private int $attempt = 0;
    private bool $stopped = false;
    private bool $subscribed = false;

    /**
     * @param \Closure(string): void $onPayload        receives each raw published message
     * @param \Closure(bool): void   $onSubscribedState called when the subscription starts or drops
     * @param \Closure(string, string): void $log      level, message
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $database,
        private readonly string $channel,
        private readonly \Closure $onPayload,
        private readonly \Closure $onSubscribedState,
        private readonly \Closure $log,
    ) {
    }

    /**
     * Must be called from inside the event loop (i.e. in onWorkerStart), never before the
     * process forks.
     */
    public function start(): void
    {
        $this->stopped = false;
        $this->open();
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->connection?->close();
        $this->connection = null;
    }

    public function isSubscribed(): bool
    {
        return $this->subscribed;
    }

    private function open(): void
    {
        if ($this->stopped) {
            return;
        }

        $connection = new AsyncTcpConnection(sprintf('tcp://%s:%d', $this->host, $this->port));
        $connection->protocol = RedisProtocol::class;

        $connection->onConnect = function (AsyncTcpConnection $connection): void {
            $this->attempt = 0;

            if ($this->database !== 0) {
                $connection->send(['SELECT', (string) $this->database]);
            }

            // Re-issued on EVERY connect, which is the whole point of this class.
            $connection->send(['SUBSCRIBE', $this->channel]);
        };

        $connection->onMessage = function (AsyncTcpConnection $connection, mixed $frame): void {
            $this->handleFrame($frame);
        };

        $connection->onError = function (AsyncTcpConnection $connection, mixed $code, mixed $message): void {
            ($this->log)('warning', sprintf('Redis connection error (%s): %s', (string) $code, (string) $message));
        };

        $connection->onClose = function (): void {
            if ($this->subscribed) {
                ($this->log)('warning', 'Redis subscription lost; real-time delivery is degraded until it is restored.');
            }

            $this->subscribed = false;
            ($this->onSubscribedState)(false);
            $this->scheduleReconnect();
        };

        $this->connection = $connection;
        $connection->connect();
    }

    /**
     * A frame is `[$type, $value]` from the RESP codec. Anything unexpected is logged and
     * dropped: a malformed internal message must never take the worker down and cut off
     * every connected browser (T039).
     */
    private function handleFrame(mixed $frame): void
    {
        if (!\is_array($frame) || \count($frame) < 2) {
            ($this->log)('warning', 'Ignoring an unreadable Redis frame.');

            return;
        }

        [$type, $value] = $frame;

        if ($type === '-') {
            ($this->log)('warning', 'Redis returned an error: ' . (string) $value);

            return;
        }

        // `+OK` from SELECT and similar simple replies carry no array payload.
        if (!\is_array($value)) {
            return;
        }

        $kind = $value[0] ?? null;

        if ($kind === 'subscribe') {
            $this->subscribed = true;
            ($this->log)('info', sprintf('Subscribed to Redis channel "%s".', $this->channel));
            ($this->onSubscribedState)(true);

            return;
        }

        if ($kind === 'message') {
            $payload = $value[2] ?? null;

            if (\is_string($payload)) {
                ($this->onPayload)($payload);
            } else {
                ($this->log)('warning', 'Ignoring a Redis message with a non-string payload.');
            }

            return;
        }

        ($this->log)('warning', 'Ignoring an unknown Redis push type: ' . var_export($kind, true));
    }

    /**
     * Bounded exponential backoff with full jitter, so a Redis restart is not met by every
     * worker reconnecting in lockstep.
     */
    private function scheduleReconnect(): void
    {
        if ($this->stopped) {
            return;
        }

        $exponential = self::BASE_BACKOFF_SECONDS * (2 ** min($this->attempt, 6));
        $capped = min(self::MAX_BACKOFF_SECONDS, $exponential);
        $delay = $capped * (0.5 + random_int(0, 1000) / 2000);

        $this->attempt++;

        Timer::add($delay, function (): void {
            $this->open();
        }, [], false);
    }
}
