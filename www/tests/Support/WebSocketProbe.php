<?php

declare(strict_types=1);

namespace app\tests\Support;

/**
 * A minimal WebSocket client, enough to assert what the real-time worker actually sends.
 *
 * It connects through nginx at /ws -- the same path a browser uses -- so these tests also
 * prove the reverse-proxy upgrade configuration works, not just the worker.
 *
 * Only the subset the contract needs is implemented: an RFC 6455 handshake, masked text
 * frames outbound, and unmasked text frames inbound (a server never masks). Anything more
 * would be a WebSocket library, which this project has no reason to own.
 */
final class WebSocketProbe
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

    public static function connect(float $timeoutSeconds = 5.0): self
    {
        $base = getenv('API_BASE_URL') ?: 'http://nginx';
        $parts = parse_url($base);
        $host = $parts['host'] ?? 'nginx';
        $port = $parts['port'] ?? 80;

        $socket = @fsockopen($host, (int) $port, $code, $message, $timeoutSeconds);

        if ($socket === false) {
            throw new \RuntimeException(sprintf('Could not reach %s:%d: %s', $host, (int) $port, $message));
        }

        stream_set_timeout($socket, (int) $timeoutSeconds);

        $key = base64_encode(random_bytes(16));

        fwrite($socket, implode("\r\n", [
            'GET /ws HTTP/1.1',
            'Host: ' . $host,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
            '',
            '',
        ]));

        $statusLine = fgets($socket);

        if ($statusLine === false || !str_contains($statusLine, '101')) {
            fclose($socket);

            throw new \RuntimeException('The WebSocket upgrade was refused: ' . var_export($statusLine, true));
        }

        // Drain the remaining handshake headers.
        while (($line = fgets($socket)) !== false && trim($line) !== '') {
            // Intentionally empty: the headers themselves are not asserted here.
        }

        return new self($socket);
    }

    public function send(string $text): void
    {
        fwrite($this->socket, self::encodeMaskedTextFrame($text));
    }

    /**
     * Reads the next text frame, or null on timeout.
     */
    public function receive(float $timeoutSeconds = 5.0): ?string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        stream_set_timeout($this->socket, max(1, (int) ceil($timeoutSeconds)));

        while (microtime(true) < $deadline) {
            $header = $this->readExactly(2);

            if ($header === null) {
                return null;
            }

            $opcode = \ord($header[0]) & 0x0F;
            $masked = (\ord($header[1]) & 0x80) !== 0;
            $length = \ord($header[1]) & 0x7F;

            if ($length === 126) {
                $extended = $this->readExactly(2);
                $length = $extended === null ? 0 : unpack('n', $extended)[1];
            } elseif ($length === 127) {
                $extended = $this->readExactly(8);
                $length = $extended === null ? 0 : unpack('J', $extended)[1];
            }

            // A server must not mask, but handle it rather than mis-decoding if it does.
            $mask = $masked ? $this->readExactly(4) : null;
            $payload = $length > 0 ? $this->readExactly($length) : '';

            if ($payload === null) {
                return null;
            }

            if ($mask !== null) {
                $unmasked = '';

                for ($i = 0, $len = \strlen($payload); $i < $len; $i++) {
                    $unmasked .= $payload[$i] ^ $mask[$i % 4];
                }

                $payload = $unmasked;
            }

            // 0x1 text, 0x8 close; ping/pong control frames are skipped.
            if ($opcode === 0x1) {
                return $payload;
            }

            if ($opcode === 0x8) {
                return null;
            }
        }

        return null;
    }

    /**
     * Reads the next frame and decodes it as an event envelope.
     *
     * @return array<string, mixed>|null
     */
    public function receiveEvent(float $timeoutSeconds = 5.0): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $frame = $this->receive(max(0.5, $deadline - microtime(true)));

            if ($frame === null) {
                return null;
            }

            // Liveness replies are not events.
            if ($frame === 'pong') {
                continue;
            }

            $decoded = json_decode($frame, true);

            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private function readExactly(int $bytes): ?string
    {
        $buffer = '';

        while (\strlen($buffer) < $bytes) {
            $chunk = fread($this->socket, $bytes - \strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($this->socket);

                if ($info['timed_out'] || feof($this->socket)) {
                    return null;
                }

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private static function encodeMaskedTextFrame(string $payload): string
    {
        $mask = random_bytes(4);
        $length = \strlen($payload);

        // FIN + text opcode.
        $frame = \chr(0x81);

        if ($length < 126) {
            $frame .= \chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= \chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= \chr(0x80 | 127) . pack('J', $length);
        }

        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        return $frame;
    }
}
