<?php

declare(strict_types=1);

namespace app\components;

/**
 * Resolves the content-hashed bundle filenames Vite produced.
 *
 * Reading the generated manifest (rather than hard-coding `/js/main.js` the way the Gulp
 * build did) is what makes immutable caching safe: every deploy changes the filename, so a
 * browser can never serve a stale bundle from cache and there is no cache-busting query
 * string to forget.
 */
final class ViteAssets
{
    /** @var array<string, array{file?: string, css?: list<string>}>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $baseUrl,
        private readonly string $entry = 'src/main.ts',
    ) {
    }

    public static function forApplication(): self
    {
        return new self(
            \Yii::getAlias('@app') . '/web/assets/app/.vite/manifest.json',
            '/assets/app/',
        );
    }

    /**
     * @return list<string> stylesheet URLs for the entry
     */
    public function styles(): array
    {
        $css = $this->entryRecord()['css'] ?? [];

        return array_map(fn (string $file): string => $this->baseUrl . $file, $css);
    }

    /**
     * @return list<string> script URLs for the entry (ES modules)
     */
    public function scripts(): array
    {
        $file = $this->entryRecord()['file'] ?? null;

        return $file === null ? [] : [$this->baseUrl . $file];
    }

    /**
     * True when the bundle has not been built yet, so the page can say so plainly instead
     * of rendering a silently dead interface.
     */
    public function isBuilt(): bool
    {
        return $this->entryRecord() !== [];
    }

    /**
     * @return array{file?: string, css?: list<string>}
     */
    private function entryRecord(): array
    {
        if ($this->manifest === null) {
            $this->manifest = $this->readManifest();
        }

        return $this->manifest[$this->entry] ?? [];
    }

    /**
     * @return array<string, array{file?: string, css?: list<string>}>
     */
    private function readManifest(): array
    {
        if (!is_file($this->manifestPath)) {
            \Yii::warning(
                'The Vite manifest is missing; the browser bundle has not been built. '
                . 'Run: docker compose exec frontend npm run build',
                'app\components\ViteAssets',
            );

            return [];
        }

        $contents = file_get_contents($this->manifestPath);

        if ($contents === false) {
            return [];
        }

        try {
            /** @var array<string, array{file?: string, css?: list<string>}> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException $exception) {
            \Yii::warning('The Vite manifest could not be parsed: ' . $exception->getMessage(), 'app\components\ViteAssets');

            return [];
        }
    }
}
