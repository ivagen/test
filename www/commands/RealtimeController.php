<?php

declare(strict_types=1);

namespace app\commands;

use app\services\RedisSubscriber;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as HttpRequest;
use Workerman\Protocols\Http\Response as HttpResponse;
use Workerman\Worker;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * The real-time worker -- the supported replacement for the 2017 PHPDaemon setup.
 *
 * It is a Yii console command so that it shares the application's configuration, event
 * classes, logging and Composer dependencies. No second framework is introduced
 * (Constitution V, plan.md).
 *
 * How it differs from what it replaces, and why:
 *
 *  - PHPDaemon polled Redis once a second and pushed the ENTIRE list to every client, so
 *    updates were up to a second late and grew with the data. Here php-fpm publishes a
 *    typed per-item event after each commit and the worker forwards it immediately.
 *  - The old daemon listened on host port 8047, a second origin that could never be
 *    secured with TLS. This one listens on an internal port only; browsers reach it at
 *    /ws on the page's own origin through nginx.
 *
 * The worker is deliberately dumb: it never reads or writes PostgreSQL and never
 * interprets an event's meaning. It relays bytes. Every question of truth is settled by
 * GET /api/items.
 */
final class RealtimeController extends Controller
{
    /**
     * Starts the WebSocket server and the Redis subscription. Blocks until the process is
     * signalled; SIGTERM triggers Workerman's graceful shutdown, which closes every client
     * connection before exiting (quickstart.md production checks).
     */
    public function actionStart(): int
    {
        $params = \Yii::$app->params;
        $channel = (string) $params['realtime.channel'];
        $wsPort = (int) $params['realtime.wsPort'];
        $healthPort = (int) $params['realtime.healthPort'];

        $this->configureLoggingForALongRunningProcess();

        // Workerman reads its sub-command from argv; this supplies "start" without letting
        // Yii's own console arguments confuse it.
        Worker::$command = 'start';
        Worker::$daemonize = false;
        Worker::$pidFile = sys_get_temp_dir() . '/realtime.pid';
        Worker::$logFile = sys_get_temp_dir() . '/realtime-workerman.log';

        $websocket = new Worker(sprintf('websocket://0.0.0.0:%d', $wsPort));
        $websocket->name = 'realtime';
        // Exactly one process: connections live in memory, and a single process keeps
        // fan-out trivially correct for an application sized in tens of users.
        $websocket->count = 1;

        // Held so that onWorkerStop can shut the subscriber down. Without this the
        // subscriber's own reconnect logic fights the shutdown: closing its socket looks
        // like an outage, it schedules another connect, and the worker never drains its
        // connections -- so Docker's grace period expires and the process is SIGKILLed
        // instead of exiting cleanly.
        $subscriber = null;

        $websocket->onWorkerStart = function (Worker $worker) use ($channel, &$subscriber): void {
            $subscriber = new RedisSubscriber(
                host: (string) \Yii::$app->get('redis')->hostname,
                port: (int) \Yii::$app->get('redis')->port,
                database: (int) \Yii::$app->get('redis')->database,
                channel: $channel,
                onPayload: function (string $payload) use ($worker): void {
                    $this->broadcast($worker, $payload);
                },
                onSubscribedState: static function (bool $subscribed): void {
                    // Clients discover degradation from their own socket state, so there is
                    // nothing to push here; this exists for the log line below.
                    \Yii::info(
                        $subscribed ? 'Real-time fan-out is active.' : 'Real-time fan-out is degraded.',
                        'app\commands\RealtimeController',
                    );
                },
                log: static function (string $level, string $message): void {
                    $level === 'info'
                        ? \Yii::info($message, 'app\commands\RealtimeController')
                        : \Yii::warning($message, 'app\commands\RealtimeController');
                },
            );

            $subscriber->start();

            \Yii::info('Real-time worker started.', 'app\commands\RealtimeController');
        };

        // SIGTERM (docker compose stop / an orchestrator draining the pod) lands here.
        $websocket->onWorkerStop = static function (Worker $worker) use (&$subscriber): void {
            $subscriber?->stop();

            // Close browser sockets explicitly so clients see a clean close and start
            // their backoff immediately, rather than waiting for a TCP timeout.
            foreach ($worker->connections as $connection) {
                $connection->close();
            }

            \Yii::info('Real-time worker stopped cleanly.', 'app\commands\RealtimeController');
        };

        $websocket->onMessage = static function (TcpConnection $connection, mixed $data): void {
            // The socket is one-way for application data. The only accepted client frame is
            // the liveness probe kept from the 2017 client, which works through proxies
            // that do not surface protocol-level pings.
            if ($data === 'ping') {
                $connection->send('pong');
            }
        };

        $websocket->onError = static function (TcpConnection $connection, mixed $code, mixed $message): void {
            \Yii::warning(
                sprintf('WebSocket connection error (%s): %s', (string) $code, (string) $message),
                'app\commands\RealtimeController',
            );
        };

        $this->startHealthEndpoint($healthPort);

        Worker::runAll();

        return ExitCode::OK;
    }

    /**
     * Relays one published event to every connected browser.
     *
     * The payload is forwarded verbatim: the worker is not a place where an event can be
     * reinterpreted, and a client that receives something it cannot parse resynchronises
     * over HTTP anyway (contracts/websocket-events.md client rule 4).
     */
    private function broadcast(Worker $worker, string $payload): void
    {
        $delivered = 0;

        foreach ($worker->connections as $connection) {
            try {
                $connection->send($payload);
                $delivered++;
            } catch (\Throwable $exception) {
                // One broken socket must not stop delivery to everyone else.
                \Yii::warning(
                    'Could not deliver an event to a client: ' . $exception->getMessage(),
                    'app\commands\RealtimeController',
                );
            }
        }

        \Yii::info(
            sprintf('Relayed a real-time event to %d client(s).', $delivered),
            'app\commands\RealtimeController',
        );
    }

    /**
     * A minimal HTTP endpoint used only by the container healthcheck. It reports that the
     * worker's event loop is alive and responsive; whether Redis is currently reachable is
     * surfaced to users through the client's degraded indicator instead, because a Redis
     * outage must not make the WebSocket service itself look broken (FR-007).
     */
    private function startHealthEndpoint(int $port): void
    {
        $health = new Worker(sprintf('http://0.0.0.0:%d', $port));
        $health->name = 'realtime-health';
        $health->count = 1;

        $health->onMessage = static function (TcpConnection $connection, HttpRequest $request): void {
            $healthy = $request->path() === '/health';

            // close(), not send(): the response is written and the socket is then shut
            // down. Keeping it alive would leave a probe that reads until EOF -- which is
            // what a plain `file_get_contents` does -- blocked until its socket timeout,
            // turning a healthy worker into a timed-out healthcheck.
            $connection->close(new HttpResponse(
                $healthy ? 200 : 404,
                [
                    'Content-Type' => 'text/plain',
                    'Cache-Control' => 'no-store',
                    'Connection' => 'close',
                ],
                $healthy ? 'ok' : 'not found',
            ));
        };
    }

    /**
     * Yii's logger batches messages and flushes at an interval sized for a request/response
     * process. In a daemon that would hide a warning for hours, so both the logger and its
     * targets are switched to write immediately.
     */
    private function configureLoggingForALongRunningProcess(): void
    {
        $logger = \Yii::getLogger();
        $logger->flushInterval = 1;

        foreach ($logger->dispatcher->targets as $target) {
            $target->exportInterval = 1;
        }
    }
}
