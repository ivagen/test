-- Legacy database fixture (tasks.md T003).
--
-- This reproduces a 2017-era production database EXACTLY as the original migration
-- www/migrations/m160815_184648_create_items_table.php would have created it, so that the
-- preservation test (T018/T023/T059) proves real user data survives the revival.
--
-- The original migration used Yii's `$this->primaryKey()` and `$this->string(255)`, which
-- the Yii PostgreSQL query builder renders as `serial NOT NULL PRIMARY KEY` and
-- `varchar(255)` (nullable). Both are reproduced verbatim below -- do NOT "improve" this
-- schema here: its whole purpose is to be the old shape.
--
-- Row selection follows quickstart.md "Legacy-data preservation": Unicode names, a
-- 255-character name, and non-contiguous ids.

DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS migration;

-- Yii's own migration bookkeeping table, as created by `yii migrate`.
CREATE TABLE migration (
    version    varchar(180) NOT NULL PRIMARY KEY,
    apply_time integer
);

INSERT INTO migration (version, apply_time) VALUES
    ('m000000_000000_base', 1471286808),
    ('m160815_184648_create_items_table', 1471286808);

-- The legacy items table.
CREATE TABLE items (
    id   serial NOT NULL PRIMARY KEY,
    name varchar(255)
);

INSERT INTO items (id, name) VALUES
    -- plain ASCII
    (1, 'Milk'),
    -- Cyrillic
    (2, 'Хліб і сіль'),
    -- gap in ids: deletions happened in production
    (7, '日本語のテスト'),
    -- emoji outside the BMP (4-byte UTF-8) plus a combining sequence
    (42, 'Café ☕ 🍰 — naïve'),
    -- another gap
    (99, 'Ω≈ç√∫˜µ≤≥÷'),
    -- boundary: exactly 255 CHARACTERS of a 2-byte code point (510 bytes).
    -- Storing this proves varchar(255) is measured in characters, not bytes, and that the
    -- new validator's 255 limit is also character-based.
    (100, repeat('ä', 255)),
    -- untrimmed legacy value: the revival trims on WRITE but must never silently rewrite
    -- data that is already stored
    (101, '  spaced out  ');

-- Keep the sequence ahead of the highest existing id, exactly as a real database would be,
-- so newly created items do not collide with preserved rows.
SELECT setval(pg_get_serial_sequence('items', 'id'), (SELECT max(id) FROM items));
