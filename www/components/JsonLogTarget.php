<?php

declare(strict_types=1);

namespace app\components;

use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;

/**
 * One JSON object per line on stderr (Constitution V: structured, useful logs).
 *
 * Writing to stderr rather than a file means `docker compose logs app` is the single place
 * to look and that nothing valuable is stranded inside a container that gets recreated.
 *
 * Every record passes through {@see redact()} first, so a credential that reaches the
 * logger by accident -- inside a DSN, a connection error or a dumped configuration array --
 * is masked before it is written (Constitution IV, T057).
 */
final class JsonLogTarget extends Target
{
    /**
     * Environment variables whose values must never appear in a log line.
     *
     * @var list<string>
     */
    private const SECRET_ENV_KEYS = [
        'APP_COOKIE_VALIDATION_KEY',
        'DB_PASSWORD',
        'POSTGRES_PASSWORD',
        'REDIS_PASSWORD',
    ];

    /**
     * Where records are written. Overridable so the redaction guarantee can be asserted
     * against a real file; production always uses the container's stderr.
     */
    public string $streamUri = 'php://stderr';

    /** @var list<string>|null */
    private ?array $secrets = null;

    public function export(): void
    {
        $stream = fopen($this->streamUri, 'ab');

        if ($stream === false) {
            return;
        }

        foreach ($this->messages as $message) {
            fwrite($stream, $this->formatRecord($message) . "\n");
        }

        fclose($stream);
    }

    /**
     * @param array{0: mixed, 1: int, 2: string, 3: float, 4?: array<int, mixed>} $message
     */
    private function formatRecord(array $message): string
    {
        [$text, $level, $category, $timestamp] = $message;

        if (!\is_string($text)) {
            $text = $text instanceof \Throwable
                ? $text::class . ': ' . $text->getMessage()
                : VarDumper::export($text);
        }

        $record = [
            'time' => gmdate('Y-m-d\TH:i:s', (int) $timestamp) . sprintf('.%03dZ', (int) (fmod($timestamp, 1) * 1000)),
            'level' => Logger::getLevelName($level),
            'category' => $category,
            'message' => $text,
        ];

        $encoded = json_encode(
            $this->redact($record),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? '{"level":"error","message":"log record could not be encoded"}' : $encoded;
    }

    /**
     * @param array<string, string> $record
     *
     * @return array<string, string>
     */
    private function redact(array $record): array
    {
        foreach ($this->secrets() as $secret) {
            $record['message'] = str_replace($secret, '[redacted]', $record['message']);
        }

        return $record;
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        if ($this->secrets !== null) {
            return $this->secrets;
        }

        $secrets = [];

        foreach (self::SECRET_ENV_KEYS as $key) {
            $value = getenv($key);

            // Short values would cause absurd false positives ("a" -> "[redacted]").
            if (\is_string($value) && mb_strlen($value) >= 6) {
                $secrets[] = $value;
            }
        }

        return $this->secrets = $secrets;
    }
}
