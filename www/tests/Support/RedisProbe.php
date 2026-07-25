<?php

declare(strict_types=1);

namespace app\tests\Support;

/**
 * A blocking Redis subscriber used only by tests, so an assertion can observe exactly what
 * the application published.
 *
 * Deliberately hand-rolled over a raw socket: the point of these tests is to check what
 * really goes over the wire, so reusing the application's own publishing code here would
 * make the test agree with the implementation by construction rather than by evidence.
 */
final class RedisProbe
{
    /** @var resource */
    private $socket;

    /**
     * @param resource $socket
     */
    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    public static function subscribe(string $channel, float $timeoutSeconds = 5.0): self
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: '6379');

        $socket = @fsockopen($host, $port, $code, $message, 5);

        if ($socket === false) {
            throw new \RuntimeException(sprintf('Could not reach Redis at %s:%d: %s', $host, $port, $message));
        }

        stream_set_timeout($socket, (int) $timeoutSeconds, (int) (fmod($timeoutSeconds, 1) * 1_000_000));

        $probe = new self($socket);

        $database = (int) (getenv('REDIS_DB') ?: '0');

        if ($database !== 0) {
            $probe->send(['SELECT', (string) $database]);
            $probe->readLine();
        }

        $probe->send(['SUBSCRIBE', $channel]);

        // Consume the subscription confirmation so the caller starts from a clean state.
        $probe->readReply();

        return $probe;
    }

    /**
     * Reads the next published message, or null when the timeout expires.
     *
     * @return array<string, mixed>|null the decoded event payload
     */
    public function nextEvent(float $timeoutSeconds = 5.0): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $reply = $this->readReply();

            if ($reply === null) {
                continue;
            }

            if (($reply[0] ?? null) !== 'message') {
                continue;
            }

            $payload = $reply[2] ?? null;

            if (!\is_string($payload)) {
                continue;
            }

            $decoded = json_decode($payload, true);

            return \is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * Asserts nothing arrives within the window -- used to prove a rejected mutation
     * publishes no event.
     */
    public function expectSilence(float $seconds = 1.0): bool
    {
        return $this->nextEvent($seconds) === null;
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function send(array $arguments): void
    {
        $command = '*' . \count($arguments) . "\r\n";

        foreach ($arguments as $argument) {
            $command .= '$' . \strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        fwrite($this->socket, $command);
    }

    private function readLine(): ?string
    {
        $line = fgets($this->socket);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /**
     * Minimal RESP reader: enough for the array replies pub/sub produces.
     *
     * @return list<mixed>|null
     */
    private function readReply(): ?array
    {
        $line = $this->readLine();

        if ($line === null || $line === '') {
            return null;
        }

        if ($line[0] !== '*') {
            return null;
        }

        $count = (int) substr($line, 1);
        $parts = [];

        for ($i = 0; $i < $count; $i++) {
            $header = $this->readLine();

            if ($header === null) {
                return null;
            }

            if ($header[0] === ':') {
                $parts[] = (int) substr($header, 1);

                continue;
            }

            if ($header[0] !== '$') {
                return null;
            }

            $length = (int) substr($header, 1);

            if ($length < 0) {
                $parts[] = null;

                continue;
            }

            $value = '';

            while (\strlen($value) < $length) {
                $chunk = fread($this->socket, $length - \strlen($value));

                if ($chunk === false || $chunk === '') {
                    return null;
                }

                $value .= $chunk;
            }

            // Consume the trailing CRLF.
            fread($this->socket, 2);

            $parts[] = $value;
        }

        return $parts;
    }
}
