<?php

declare(strict_types=1);

namespace app\tests\integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Proves that real 2017 data survives the revival (tasks.md T018/T023/T059, FR-001, SC-002).
 *
 * The whole test runs against a SCRATCH database that it creates and drops itself. It
 * never touches the application database, and the only object it destroys is one it
 * created under a name it generated -- which is what makes the reversibility check below
 * safe to run at all.
 */
final class MigrationPreservationTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/legacy-items.sql';

    private static string $database = '';
    private static ?\PDO $admin = null;

    public static function setUpBeforeClass(): void
    {
        self::$database = 'preservation_test_' . bin2hex(random_bytes(6));
        self::$admin = self::connect(getenv('DB_NAME') ?: 'app');

        // CREATE DATABASE cannot run inside a transaction, hence no DatabaseTestCase here.
        self::$admin->exec(sprintf('CREATE DATABASE %s', self::quoteIdentifier(self::$database)));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && self::$database !== '') {
            self::$admin->exec(sprintf(
                'DROP DATABASE IF EXISTS %s WITH (FORCE)',
                self::quoteIdentifier(self::$database),
            ));
        }

        self::$admin = null;
    }

    private static function connect(string $database): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            getenv('DB_HOST') ?: 'postgres',
            (int) (getenv('DB_PORT') ?: '5432'),
            $database,
        );

        return new \PDO($dsn, getenv('DB_USER') ?: 'app', getenv('DB_PASSWORD') ?: '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Runs `yii migrate` against the scratch database by overriding DB_NAME for the child
     * process only -- the same code path an operator uses, not a test-only shortcut.
     *
     * @return array{status: int, output: string}
     */
    private function runMigrate(string ...$arguments): array
    {
        $command = sprintf(
            'DB_NAME=%s php %s %s 2>&1',
            escapeshellarg(self::$database),
            escapeshellarg(\dirname(__DIR__, 2) . '/yii'),
            implode(' ', array_map(escapeshellarg(...), [...$arguments, '--interactive=0'])),
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        return ['status' => $status, 'output' => implode("\n", $output)];
    }

    /**
     * @return array<int, string|null>
     */
    private function snapshot(\PDO $connection): array
    {
        $rows = $connection->query('SELECT id, name FROM items ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);

        $snapshot = [];

        foreach ($rows as $row) {
            $snapshot[(int) $row['id']] = $row['name'];
        }

        return $snapshot;
    }

    public function testLegacyDatabaseIsSeededFromTheOriginalSchema(): \PDO
    {
        $fixture = file_get_contents(self::FIXTURE);

        self::assertIsString($fixture, 'The legacy fixture must be readable.');

        $legacy = self::connect(self::$database);
        $legacy->exec($fixture);

        $snapshot = $this->snapshot($legacy);

        // Exactly the rows the fixture defines, including the deliberate id gaps.
        self::assertSame([1, 2, 7, 42, 99, 100, 101], array_keys($snapshot));
        self::assertSame('Хліб і сіль', $snapshot[2]);
        self::assertSame(255, mb_strlen((string) $snapshot[100]));
        self::assertSame('  spaced out  ', $snapshot[101]);

        // The fixture must reproduce the legacy shape, not an improved one: `name` is
        // nullable in the 2017 schema and stays that way (data-model.md defers adding a
        // NOT NULL constraint until a null-row remediation is approved by the owner).
        $nullable = $legacy
            ->query("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'items' AND column_name = 'name'")
            ->fetchColumn();
        self::assertSame('YES', $nullable);

        return $legacy;
    }

    /**
     * The core requirement: applying the revived migration set to a legacy database must
     * change nothing about the stored rows.
     */
    #[Depends('testLegacyDatabaseIsSeededFromTheOriginalSchema')]
    public function testMigrationPreservesEveryLegacyRow(\PDO $legacy): \PDO
    {
        $before = $this->snapshot($legacy);
        $tableOidBefore = $legacy->query("SELECT 'items'::regclass::oid")->fetchColumn();

        $result = $this->runMigrate('migrate');

        self::assertSame(0, $result['status'], 'Migration must succeed on a legacy database: ' . $result['output']);

        $after = $this->snapshot($legacy);

        self::assertSame($before, $after, 'Every legacy id and name must survive migration unchanged.');

        // A drop-and-recreate would preserve the *values* while destroying the table. The
        // OID proves the original table object itself is still there.
        $tableOidAfter = $legacy->query("SELECT 'items'::regclass::oid")->fetchColumn();
        self::assertSame($tableOidBefore, $tableOidAfter, 'The items table must never be dropped and recreated.');

        return $legacy;
    }

    /**
     * quickstart.md: "Running the migration command a second time succeeds with no pending
     * migration" (spec US1 acceptance scenario 2).
     */
    #[Depends('testMigrationPreservesEveryLegacyRow')]
    public function testMigrationIsIdempotent(\PDO $legacy): \PDO
    {
        $before = $this->snapshot($legacy);

        $result = $this->runMigrate('migrate');

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('No new migrations found', $result['output']);
        self::assertSame($before, $this->snapshot($legacy), 'A repeat migration must not alter data.');

        return $legacy;
    }

    /**
     * New rows must continue after the highest preserved id rather than colliding with it,
     * which is only true if the sequence was left alone.
     */
    #[Depends('testMigrationIsIdempotent')]
    public function testSequenceContinuesAfterPreservedIds(\PDO $legacy): \PDO
    {
        $legacy->exec("INSERT INTO items (name) VALUES ('created after migration')");

        $id = (int) $legacy->query('SELECT max(id) FROM items')->fetchColumn();

        self::assertGreaterThan(101, $id, 'A new item must not reuse or collide with a preserved id.');

        $legacy->exec(sprintf('DELETE FROM items WHERE id = %d', $id));

        return $legacy;
    }

    /**
     * Characterises the ONE edge case the revival deliberately left open.
     *
     * The 2017 schema allows `name` to be NULL, and data-model.md is explicit that adding a
     * NOT NULL constraint requires first detecting legacy null rows and getting owner
     * approval for a non-lossy remediation. No such constraint was added, so this test
     * pins down what actually happens today rather than leaving it unknown:
     *
     *   - a null-name row is PRESERVED, exactly as FR-001 requires;
     *   - it is detectable with the query below, which is what an operator would run before
     *     any future NOT NULL migration;
     *   - the API would serialise it as an empty string, which does not satisfy the
     *     `minLength: 1` in contracts/openapi.yaml.
     *
     * That last point is a known, reported limitation, not an accident. It cannot arise
     * from any write the revived application performs -- validation makes a null or blank
     * name impossible -- only from data that predates it.
     */
    #[Depends('testSequenceContinuesAfterPreservedIds')]
    public function testALegacyNullNameIsPreservedAndDetectable(\PDO $legacy): \PDO
    {
        $legacy->exec('INSERT INTO items (id, name) VALUES (500, NULL)');

        $nullRows = (int) $legacy
            ->query('SELECT count(*) FROM items WHERE name IS NULL')
            ->fetchColumn();

        self::assertSame(1, $nullRows, 'A null name must survive rather than be rewritten.');

        $result = $this->runMigrate('migrate');
        self::assertSame(0, $result['status'], $result['output']);

        self::assertSame(
            1,
            (int) $legacy->query('SELECT count(*) FROM items WHERE name IS NULL')->fetchColumn(),
            'Migrating must not silently alter or drop a null-name row.',
        );

        // The fixture that represents a real production database has none, so the revival
        // is not shipping this problem to anyone today.
        $legacy->exec('DELETE FROM items WHERE id = 500');

        return $legacy;
    }

    /**
     * Constitution I: migrations "MUST be reversible whenever the database engine permits
     * it". Verified here, on the disposable scratch database only -- never against data
     * anyone cares about.
     */
    #[Depends('testALegacyNullNameIsPreservedAndDetectable')]
    public function testMigrationsAreReversible(\PDO $legacy): void
    {
        $down = $this->runMigrate('migrate/down', 'all');
        self::assertSame(0, $down['status'], 'Rolling every migration back must succeed: ' . $down['output']);

        $tables = $legacy
            ->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'items'")
            ->fetchColumn();
        self::assertSame(0, (int) $tables, 'Rolling back must remove the table it created.');

        $up = $this->runMigrate('migrate');
        self::assertSame(0, $up['status'], 'Re-applying from scratch must succeed: ' . $up['output']);

        $columns = $legacy
            ->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'items' ORDER BY ordinal_position")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame(
            ['id' => 'integer', 'name' => 'character varying'],
            $columns,
            'A rebuilt schema must be identical to the legacy one.',
        );
    }
}
