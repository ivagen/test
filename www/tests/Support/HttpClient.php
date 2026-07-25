<?php

declare(strict_types=1);

namespace app\tests\Support;

/**
 * A tiny cookie-aware HTTP client for contract tests.
 *
 * Contract tests must exercise the API the way a browser does -- through nginx, over real
 * HTTP, with real cookies and a real CSRF token. Calling controllers in-process would
 * bypass routing, the JSON body parser, CSRF validation and the error handler, which are
 * exactly the layers the contract describes.
 */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    private ?string $csrfToken = null;

    public function __construct(private readonly string $baseUrl)
    {
    }

    public static function fromEnvironment(): self
    {
        $base = getenv('API_BASE_URL');

        return new self(\is_string($base) && $base !== '' ? rtrim($base, '/') : 'http://nginx');
    }

    /**
     * Loads the page the browser loads, capturing the CSRF cookie and the token that the
     * client reads from the <meta> tag.
     */
    public function csrfToken(): string
    {
        if ($this->csrfToken !== null) {
            return $this->csrfToken;
        }

        $response = $this->request('GET', '/');

        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $response->body, $matches) !== 1) {
            throw new \RuntimeException('The page did not expose a csrf-token meta tag.');
        }

        return $this->csrfToken = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = [],
    ): HttpResponse {
        $handle = curl_init($this->baseUrl . $path);

        if ($handle === false) {
            throw new \RuntimeException('Could not initialise curl.');
        }

        $requestHeaders = [];

        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        if ($this->cookies !== []) {
            $pairs = [];

            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }

            $requestHeaders[] = 'Cookie: ' . implode('; ', $pairs);
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => $body ?? '',
        ]);

        $raw = curl_exec($handle);

        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new \RuntimeException(sprintf('%s %s failed: %s', $method, $path, $error));
        }

        \assert(\is_string($raw));

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        $parsed = $this->parseHeaders($rawHeaders);
        $this->captureCookies($parsed);

        return new HttpResponse($status, $parsed, $responseBody);
    }

    /**
     * @param array<string, string> $headers
     */
    public function json(string $method, string $path, mixed $payload = null, array $headers = []): HttpResponse
    {
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $this->request($method, $path, $body, $headers + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);
    }

    /**
     * A mutation exactly as the browser client performs it: JSON body plus the CSRF token
     * in the X-CSRF-Token header.
     *
     * @param array<string, string> $headers
     */
    public function mutate(string $method, string $path, mixed $payload = null, array $headers = []): HttpResponse
    {
        return $this->json($method, $path, $payload, $headers + ['X-CSRF-Token' => $this->csrfToken()]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }

        return $headers;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function captureCookies(array $headers): void
    {
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $pair = explode(';', $cookie, 2)[0];

            if (!str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $this->cookies[trim($name)] = trim($value);
        }
    }
}
