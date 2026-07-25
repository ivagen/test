<?php

declare(strict_types=1);

namespace app\components;

/**
 * Validated access to runtime configuration (FR-008).
 *
 * Every setting comes from the process environment; nothing sensitive is ever committed.
 * This class is loaded by the Composer autoloader rather than Yii's, because the
 * configuration arrays in config/ are built before the Yii application (and therefore the
 * `@app` alias) exists.
 */
final class Environment
{
    public const DEV = 'dev';
    public const PROD = 'prod';

    /**
     * Values that are fine for a laptop but must never reach production. `.env.example`
     * ships the first one so a clean clone starts without manual key generation, while
     * APP_ENV=prod still refuses to boot with it.
     *
     * @var list<string>
     */
    private const PLACEHOLDER_SECRETS = [
        'dev-only-insecure-placeholder-change-me',
        'changeme',
        'secret',
        // The key that was hard-coded in the 2017 config/web.php. If it ever reappears,
        // treat it as compromised: it has been public in this repository's history.
        'aehsrykdyulfy',
    ];

    public static function name(): string
    {
        $value = self::string('APP_ENV', self::DEV);

        return $value === self::PROD ? self::PROD : self::DEV;
    }

    public static function isProduction(): bool
    {
        return self::name() === self::PROD;
    }

    public static function string(string $key, ?string $default = null): string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            if ($default === null) {
                throw new \RuntimeException(sprintf(
                    'Required environment variable %s is not set. See .env.example.',
                    $key,
                ));
            }

            return $default;
        }

        return $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new \RuntimeException(sprintf(
                'Environment variable %s must be an integer, got "%s".',
                $key,
                $value,
            ));
        }

        return (int) $value;
    }

    /**
     * Reads a secret, refusing to start production with a known-insecure placeholder.
     *
     * The check is deliberately fail-closed: a misconfigured production deployment stops
     * immediately instead of silently signing cookies with a value published on GitHub.
     */
    public static function secret(string $key): string
    {
        $value = self::string($key, self::isProduction() ? null : 'dev-only-insecure-placeholder-change-me');

        if (self::isProduction() && \in_array($value, self::PLACEHOLDER_SECRETS, true)) {
            throw new \RuntimeException(sprintf(
                'Environment variable %s still holds a development placeholder. '
                . 'Generate a real secret (openssl rand -base64 32) before running APP_ENV=prod.',
                $key,
            ));
        }

        return $value;
    }
}
