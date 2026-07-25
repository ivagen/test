<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\models\Items;
use app\tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Name validation boundaries (tasks.md T024, FR-003).
 *
 * FR-003: "The server MUST trim names, require 1-255 Unicode characters after trimming,
 * and return machine-readable validation errors."
 *
 * The 255 limit is measured in CHARACTERS, not bytes -- the legacy fixture stores a
 * 255-character 2-byte name (510 bytes) that must remain valid.
 */
final class ItemsTest extends DatabaseTestCase
{
    public function testRejectsMissingName(): void
    {
        $item = new Items();

        self::assertFalse($item->validate(), 'A missing name must be rejected.');
        self::assertArrayHasKey('name', $item->getErrors());
    }

    public function testRejectsEmptyName(): void
    {
        $item = new Items(['name' => '']);

        self::assertFalse($item->validate(), 'An empty name must be rejected.');
        self::assertArrayHasKey('name', $item->getErrors());
    }

    /**
     * The legacy model used only `required` + `string(max)`, so a name of pure whitespace
     * passed validation and produced a blank-looking row. It must now be rejected.
     */
    #[DataProvider('whitespaceOnlyNames')]
    public function testRejectsWhitespaceOnlyName(string $name): void
    {
        $item = new Items(['name' => $name]);

        self::assertFalse($item->validate(), sprintf('Whitespace-only name %s must be rejected.', var_export($name, true)));
        self::assertArrayHasKey('name', $item->getErrors());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whitespaceOnlyNames(): iterable
    {
        yield 'spaces' => ['   '];
        yield 'tab' => ["\t"];
        yield 'newline' => ["\n"];
        yield 'mixed' => [" \t\r\n "];
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $item = new Items(['name' => "  Milk\t"]);

        self::assertTrue($item->validate(), implode('; ', $item->getFirstErrors()));
        self::assertSame('Milk', $item->name, 'The name must be trimmed before it is stored.');
    }

    public function testAcceptsSingleCharacterName(): void
    {
        $item = new Items(['name' => 'x']);

        self::assertTrue($item->validate(), implode('; ', $item->getFirstErrors()));
    }

    public function testAcceptsExactly255Characters(): void
    {
        $item = new Items(['name' => str_repeat('a', 255)]);

        self::assertTrue($item->validate(), implode('; ', $item->getFirstErrors()));
    }

    /**
     * 255 two-byte characters are 510 bytes. A byte-based limit would wrongly reject this,
     * and the legacy fixture contains exactly such a row.
     */
    public function testAcceptsExactly255UnicodeCharacters(): void
    {
        $name = str_repeat('ä', 255);

        self::assertSame(255, mb_strlen($name));
        self::assertSame(510, \strlen($name));

        $item = new Items(['name' => $name]);

        self::assertTrue($item->validate(), implode('; ', $item->getFirstErrors()));
    }

    public function testRejects256Characters(): void
    {
        $item = new Items(['name' => str_repeat('a', 256)]);

        self::assertFalse($item->validate(), 'A 256-character name must be rejected.');
        self::assertArrayHasKey('name', $item->getErrors());
    }

    /**
     * Trimming happens BEFORE the length check, so 255 characters wrapped in spaces is
     * valid rather than a 257-character overflow.
     */
    public function testTrimsBeforeMeasuringLength(): void
    {
        $item = new Items(['name' => ' ' . str_repeat('a', 255) . ' ']);

        self::assertTrue($item->validate(), implode('; ', $item->getFirstErrors()));
        self::assertSame(255, mb_strlen((string) $item->name));
    }

    #[DataProvider('unicodeNames')]
    public function testPreservesUnicodeExactly(string $name): void
    {
        $item = new Items(['name' => $name]);

        self::assertTrue($item->validate(), implode('; ', $item->getFirstErrors()));
        self::assertSame($name, $item->name, 'Validation must not mangle Unicode.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unicodeNames(): iterable
    {
        yield 'cyrillic' => ['Хліб і сіль'];
        yield 'cjk' => ['日本語のテスト'];
        yield 'emoji' => ['Café ☕ 🍰'];
        yield 'symbols' => ['Ω≈ç√∫˜µ≤≥÷'];
    }

    /**
     * The API representation is exactly the OpenAPI `Item` schema: two properties, an
     * integer id and a string name, and nothing else.
     */
    public function testApiRepresentationMatchesTheContract(): void
    {
        $item = new Items(['name' => 'Milk']);

        self::assertTrue($item->save(), implode('; ', $item->getFirstErrors()));

        $representation = $item->toApiRepresentation();

        self::assertSame(['id', 'name'], array_keys($representation));
        self::assertIsInt($representation['id']);
        self::assertGreaterThanOrEqual(1, $representation['id']);
        self::assertSame('Milk', $representation['name']);
    }

    /**
     * Persisting must store the trimmed value, not the raw input.
     */
    public function testStoresTrimmedName(): void
    {
        $item = new Items(['name' => '  Bread  ']);

        self::assertTrue($item->save(), implode('; ', $item->getFirstErrors()));

        $reloaded = Items::findOne($item->id);

        self::assertNotNull($reloaded);
        self::assertSame('Bread', $reloaded->name);
    }
}
