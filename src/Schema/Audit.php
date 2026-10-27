<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use Systopic\Db\Connection;

/**
 * The manual counterpart to the provisioner — the full comparison it
 * deliberately does not make.
 *
 * The provisioner only ever acts losslessly and only at an error. Everything
 * lossy — dropping an orphaned column, dropping a whole table, narrowing a
 * type — it leaves lying around, and rightly so: nobody should lose data
 * because a property was renamed.
 *
 * `Audit` is where that gets looked at. It is called by hand, so it may be
 * expensive: every registered class against `information_schema`, plus a row
 * count for anything it proposes to drop.
 *
 * **It proposes, it does not act.** `run()` returns a list of actions, the
 * caller shows them, and only what comes back through `apply()` gets executed.
 * That split is the whole safety: the dangerous half of schema management is
 * exactly one confirmed call away, never a side effect.
 *
 * Row counts instead of just names — that is the difference between "something
 * is orphaned here" and "you can throw that away safely":
 *
 *     cms_pages
 *       page_teaser    varchar(255)  orphaned   143 of 143 rows filled
 *       page_fulltext  text          orphaned     0 of 143 filled   -> safe
 *       page_views     smallint      declared int unsigned          -> MODIFY
 *     cms_keywords     whole table orphaned, 0 rows
 */
final class Audit
{
    /** @var list<AuditAction> */
    private array $actions = [];

    /** @var list<array{action: AuditAction, message: string}> */
    private array $failures = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?Migrations $migrations = null,
    ) {}

    /**
     * Compare every registered class against the database.
     *
     * @param  list<class-string>|null $classes  defaults to everything the
     *                                           registry knows
     * @return list<AuditAction>
     */
    public function run(?array $classes = null): array
    {
        $this->actions = [];
        // A subset cannot judge orphans: every table nobody passed in would
        // look abandoned. Only a full audit gets to say "whole table
        // orphaned" — which is also the only situation in which that sentence
        // means anything.
        $full = $classes === null;
        $classes ??= array_values(Registry::all());

        $known = [];
        $prefixes = [Schema::defaultTablePrefix() => true];
        foreach ($classes as $class) {
            $table = Schema::of($class);
            $known[$table->name] = true;
            $prefix = defined($class . '::TABLE_PREFIX') ? (string) constant($class . '::TABLE_PREFIX') : '';
            $prefixes[$prefix !== '' ? $prefix : Schema::defaultTablePrefix()] = true;
            $this->auditTable($table);
        }

        if ($full) {
            $this->auditOrphanTables($known, array_keys($prefixes));
        }
        $this->auditMigrations();

        $this->sortByDanger();
        return $this->actions;
    }

    /**
     * Least dangerous first — and here rather than in the caller.
     *
     * Collection order is table by table and column by column, so a `MODIFY`
     * on the second table comes before a `CREATE INDEX` on the first. That is
     * fine to look at and wrong to execute: what creates should happen before
     * what drops, and a caller applying the list in the order it arrives got
     * neither the order it displayed nor a safe one.
     *
     * It cost a real failure to notice. A `MODIFY` that could not work sat
     * early in the collected order, execution stopped there, and nineteen
     * harmless constraints behind it never ran — while the screen had shown
     * them first.
     */
    private function sortByDanger(): void
    {
        $rank = array_flip(array_map(static fn(AuditKind $k) => $k->value, AuditKind::cases()));
        usort($this->actions, static fn(AuditAction $a, AuditAction $b) =>
            $rank[$a->kind->value] <=> $rank[$b->kind->value]
                ?: strcmp($a->subject, $b->subject));
    }

    /** @return list<AuditAction> */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * Execute exactly the actions handed in — nothing else.
     *
     * The caller has shown them and got a confirmation. Passing the actions
     * back in rather than a flag is deliberate: it makes "the user confirmed
     * *these*" the thing that travels, instead of "the user confirmed
     * something".
     *
     * @param  list<AuditAction> $actions
     * @return list<string> the statements that ran
     */
    public function apply(
        array $actions,
        bool $stopOnError = false,
        bool $withoutForeignKeyChecks = false,
    ): array {
        $this->failures = [];
        $done = [];

        $platform = $this->connection->platform();
        if ($withoutForeignKeyChecks) {
            $this->connection->exec($platform->foreignKeyChecks(false));
        }

        try {
            foreach ($actions as $action) {
                try {
                    foreach ($action->statements as $sql) {
                        $this->connection->exec($sql);
                        $done[] = $sql;
                    }
                } catch (\Throwable $e) {
                    // Carrying on is the point. Thirty actions are independent
                    // of each other far more often than not — they are sorted
                    // by kind, not chained — and stopping at the first one
                    // leaves somebody knowing exactly one thing about their
                    // schema when they could have known thirty. The failures
                    // are collected and reported instead of thrown away.
                    $this->failures[] = [
                        'action'  => $action,
                        'message' => $e->getMessage(),
                    ];
                    if ($stopOnError) {
                        throw $e;
                    }
                }
            }
        } finally {
            // Even on the way out through an exception: leaving a session with
            // foreign keys switched off would be worse than the error itself.
            if ($withoutForeignKeyChecks) {
                $this->connection->exec($platform->foreignKeyChecks(true));
            }
        }

        return $done;
    }

    /**
     * What went wrong in the last `apply()`.
     *
     * @return list<array{action: AuditAction, message: string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    // ------------------------------------------------------------- tables

    private function auditTable(Table $table): void
    {
        $actual = $this->columnsOf($table->name);
        if ($actual === []) {
            $this->actions[] = new AuditAction(
                kind: AuditKind::CreateTable,
                subject: $table->name,
                describe: 'table missing',
                statements: [$this->connection->platform()->createTable($table, withForeignKeys: false)],
                lossless: true,
            );
            return;
        }

        $rows = $this->rowCount($table->name);

        // declared but missing
        foreach ($table->columns as $name => $column) {
            if (!isset($actual[$name])) {
                $this->actions[] = new AuditAction(
                    kind: AuditKind::AddColumn,
                    subject: $table->name . '.' . $name,
                    describe: 'declared, missing in the database',
                    statements: [$this->connection->platform()->addColumn($table, $name)],
                    lossless: true,
                );
                continue;
            }
            // present, but a different type
            //
            // Compared **without the default**, for the same reason
            // `Column::signature()` leaves it out: in the database a default
            // has usually grown historically and says nothing about the
            // structure. A `DEFAULT ''` that the declaration has and the
            // column does not is not worth a MODIFY.
            $declared = $this->connection->platform()->renderColumn($column);
            if (Column::comparable($actual[$name]['rendered']) !== Column::comparable($declared)) {
                $this->actions[] = new AuditAction(
                    kind: AuditKind::ModifyColumn,
                    subject: $table->name . '.' . $name,
                    describe: sprintf('database %s, declared %s', $actual[$name]['rendered'], $declared),
                    statements: [$this->connection->platform()->modifyColumn($table, $name)],
                    // Widening is lossless, narrowing is not — and the audit
                    // cannot tell them apart without understanding the type.
                    // So it says so instead of guessing.
                    lossless: false,
                );
            }
        }

        // present but no longer declared
        foreach ($actual as $name => $info) {
            if (isset($table->columns[$name])) {
                continue;
            }
            $filled = $this->filledCount($table->name, $name, (string) ($info['DATA_TYPE'] ?? ''));
            $this->actions[] = new AuditAction(
                kind: AuditKind::DropColumn,
                subject: $table->name . '.' . $name,
                describe: sprintf(
                    '%s orphaned, %d of %d rows filled%s',
                    $info['rendered'], $filled, $rows,
                    $filled === 0 ? '  -> safe' : '',
                ),
                statements: [
                    'ALTER TABLE ' . $this->connection->platform()->quote($table->name)
                    . ' DROP COLUMN ' . $this->connection->platform()->quote($name),
                ],
                lossless: false,
                filled: $filled,
            );
        }

        $this->auditConstraints($table, $actual);
        $this->auditIndices($table, $actual);
    }

    /**
     * Indexes — and why they belong here and not in the provisioner.
     *
     * A missing index never raises an error. It makes things slow, and there
     * is no MySQL code for "slower than it should be". So it cannot be
     * repaired at the error the way a missing column is; it can only be
     * noticed by comparing, which is what this class is for.
     *
     * Two rules keep it from proposing nonsense:
     *
     *  - **Composite indexes are left alone.** The declaration can only
     *    express one column at a time, so it has nothing to say about an
     *    index over three — and silence is not the same as disagreement.
     *  - **A column the model does not know is left alone.** It is already
     *    covered by a `DropColumn` action; proposing to drop its index as
     *    well would be the same finding twice.
     *
     * @param array<string, array<string, mixed>> $actual
     */
    private function auditIndices(Table $table, array $actual): void
    {
        // What the declaration asks for: an explicit #[Col(index: …)], plus
        // every foreign key column, which is indexed whether anybody said so
        // or not.
        $declared = [];
        foreach ($table->columns as $name => $column) {
            if ($column->index !== null) {
                $declared[$name] = true;
            }
        }
        foreach (array_keys($table->foreignKeys) as $name) {
            // Except the leftmost key column: a primary key is an index on its
            // own prefix, so `PRIMARY KEY (tin_tag_id, tin_node_id)` already
            // covers `tin_tag_id`. Asking for a second index on it would be a
            // finding that can never be satisfied — create it and the next run
            // proposes dropping it again. `tin_node_id` is a different matter
            // and is the one this rule is meant to surface.
            if ($name === ($table->key[0] ?? null)) {
                continue;
            }
            if (isset($table->columns[$name])) {
                $declared[$name] = true;
            }
        }

        /** @var array<string, list<string>> column => index names on it */
        $onColumn = [];
        foreach ($this->connection->all(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLS,
                    COUNT(*) AS N
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME <> ?
              GROUP BY INDEX_NAME',
            [$table->name, 'PRIMARY'],
        ) as $row) {
            if ((int) $row['N'] !== 1) {
                continue;   // composite — not ours to judge
            }
            $onColumn[(string) $row['COLS']][] = (string) $row['INDEX_NAME'];
        }

        $platform = $this->connection->platform();

        // declared, but nothing there
        foreach (array_keys($declared) as $column) {
            if (isset($onColumn[$column]) || !isset($actual[$column])) {
                continue;
            }
            $this->actions[] = new AuditAction(
                kind: AuditKind::CreateIndex,
                subject: $table->name . '.' . $column,
                describe: isset($table->foreignKeys[$column])
                    ? 'foreign key column without an index'
                    : 'index declared, missing in the database',
                statements: [$platform->addIndex($table, $column)],
                lossless: true,
            );
        }

        // there, but nobody asked for it
        foreach ($onColumn as $column => $indices) {
            if (!isset($table->columns[$column])) {
                continue;   // the column itself is already a finding
            }
            $keep = null;
            if (isset($declared[$column])) {
                // One of them is the declared index. Prefer the one already
                // named the way this platform would name it, so a rerun does
                // not keep changing its mind about which to drop.
                $wanted = $platform->indexName($table, $column);
                $keep = in_array($wanted, $indices, true) ? $wanted : $indices[0];
            }
            foreach ($indices as $index) {
                if ($index === $keep) {
                    continue;
                }
                $this->actions[] = new AuditAction(
                    kind: AuditKind::DropIndex,
                    subject: $table->name . '.' . $index,
                    describe: $keep === null
                        ? sprintf('index on %s, not declared', $column)
                        : sprintf('second index on %s, `%s` already covers it', $column, $keep),
                    statements: [$platform->dropIndex($table->name, $index)],
                    // An index holds no data of its own; it is rebuilt from
                    // the table. Nothing is lost, only speed.
                    lossless: true,
                );
            }
        }
    }

    /**
     * Tables under a prefix some class uses, with no class of their own.
     *
     * Every prefix of the registered classes counts, not only the
     * installation's own: `usm_` belongs to the system package, and a table it
     * renamed (`usm_login_codes` → `usm_logincodes`) would otherwise stay in
     * the database forever, unseen. The registry is complete here - a full
     * audit runs after `Registry::scanTables()` - so a prefix that is known
     * is known with all of its tables.
     *
     * @param array<string, true> $known
     * @param list<string>        $prefixes
     */
    private function auditOrphanTables(array $known, array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '') {
                continue;   // without a prefix every foreign table would look orphaned
            }
            $this->auditOrphanTablesOf($prefix, $known);
        }
    }

    /** @param array<string, true> $known */
    private function auditOrphanTablesOf(string $prefix, array $known): void
    {
        foreach ($this->connection->all(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            [$prefix . '\_%'],
        ) as $row) {
            $name = $row['TABLE_NAME'];
            if (isset($known[$name])) {
                continue;
            }
            // A table this layer cannot describe is not an orphan — it is out
            // of scope, and the two are not the same thing.
            //
            // That used to exclude every junction: the whole layer rested on a
            // single `<prefix>_id`, so `cms_tags_in_nodes` with `(tin_tag_id,
            // tin_node_id)` had no class, and the absence of one would have
            // looked like evidence of abandonment for a table the application
            // reads on every panel load. Since the key is a tuple, a junction
            // has a class like anything else and is judged like anything else.
            //
            // What remains out of scope is a table with **no** primary key at
            // all. There is nothing to identify a row by, so this layer has
            // nothing to say about it — including whether it is still in use.
            if ($this->primaryColumnsOf($name) === []) {
                continue;
            }
            $rows = $this->rowCount($name);
            $this->actions[] = new AuditAction(
                kind: AuditKind::DropTable,
                subject: $name,
                describe: sprintf('whole table orphaned, %d rows%s', $rows, $rows === 0 ? '  -> safe' : ''),
                statements: ['DROP TABLE ' . $this->connection->platform()->quote($name)],
                lossless: false,
                filled: $rows,
            );
        }
    }

    /**
     * The constraints the provisioner deliberately does not create.
     *
     * `CREATE TABLE` with an FK onto a table that does not exist yet fails
     * with 1824, and while provisioning that is the normal case. So the
     * relation lives in the declaration — where it counts for the ORM — and
     * the constraint gets set here, once every table is there.
     *
     * @param array<string, array<string, mixed>> $actual
     */
    private function auditConstraints(Table $table, array $actual): void
    {
        if ($table->foreignKeys === []) {
            return;
        }
        $existing = array_column($this->connection->all(
            'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table->name],
        ), 'COLUMN_NAME');

        $platform = $this->connection->platform();

        foreach ($table->foreignKeys as $column => $fk) {
            if (in_array($column, $existing, true) || !isset($actual[$column])) {
                continue;
            }
            $q = $this->connection->platform()->quote(...);

            // Ask the data before proposing the constraint. Adding a foreign
            // key to a table that grew without one fails with 1452 whenever a
            // single child row points at a parent that is not there, and
            // finding that out one error at a time — after a list that
            // promised nineteen harmless actions — is the worst way to learn
            // it.
            $violations = $this->danglingRows($table->name, $column, $fk);

            // The step that makes the constraint possible, proposed next to
            // it rather than left as homework. A reference to a row that is
            // gone carries no information — the id stopped meaning anything
            // the moment the parent was deleted — so clearing it discards the
            // shape of a mistake, not a fact.
            //
            // Only where the column can actually hold NULL **today**. The
            // declaration may already say nullable while the database does
            // not; then the MODIFY has to come first, and it does, on the
            // next run. Proposing an UPDATE that cannot work would be worse
            // than proposing nothing.
            if ($violations > 0 && ($actual[$column]['IS_NULLABLE'] ?? 'NO') === 'YES') {
                $this->actions[] = new AuditAction(
                    kind: AuditKind::ClearOrphans,
                    subject: $table->name . '.' . $column,
                    describe: sprintf(
                        '%d rows point at a missing %s.%s — set them to NULL',
                        $violations, $fk->table, $fk->references,
                    ),
                    statements: [$platform->clearOrphans($table->name, $column, $fk)],
                    lossless: false,
                    filled: $violations,
                );
            }

            $this->actions[] = new AuditAction(
                kind: AuditKind::AddConstraint,
                subject: $table->name . '.' . $column,
                describe: 'foreign key missing -> ' . $fk->table . '.' . $fk->references
                    . ($violations > 0
                        ? sprintf('  -> %d rows point nowhere, would fail', $violations)
                        : ''),
                statements: [
                    'ALTER TABLE ' . $q($table->name)
                    . ' ADD CONSTRAINT ' . $q('fk_' . $table->name . '_' . $column)
                    . ' FOREIGN KEY (' . $q($column) . ')'
                    . ' REFERENCES ' . $q($fk->table) . ' (' . $q($fk->references) . ')',
                ],
                lossless: true,
                violations: $violations,
            );
        }
    }

    /** Hooked migrations that have not run yet. */
    private function auditMigrations(): void
    {
        foreach ($this->migrations?->hooks() ?? [] as $hook) {
            $this->actions[] = new AuditAction(
                kind: AuditKind::Migration,
                subject: $hook,
                describe: 'migration hook registered',
                statements: [],
                lossless: true,
            );
        }
    }

    // -------------------------------------------------------------- lookup

    /** @return array<string, array<string, mixed>> */
    private function columnsOf(string $table): array
    {
        $out = [];
        foreach ($this->connection->all(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, EXTRA, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$table],
        ) as $row) {
            $row['COLUMN_DEFAULT'] = Column::schemaDefault($row['COLUMN_DEFAULT'], $this->connection->isMariaDb());
            $rendered = $row['COLUMN_TYPE'] . ($row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL');
            if (str_contains((string) $row['EXTRA'], 'auto_increment')) {
                $rendered .= ' AUTO_INCREMENT';
            } elseif ($row['COLUMN_DEFAULT'] !== null) {
                $rendered .= ' DEFAULT ' . $this->literal($row['COLUMN_DEFAULT']);
            }
            $out[$row['COLUMN_NAME']] = ['rendered' => $rendered] + $row;
        }
        return $out;
    }

    private function literal(mixed $v): string
    {
        return is_numeric($v) ? (string) $v : "'" . str_replace("'", "''", (string) $v) . "'";
    }


    /**
     * Child rows whose parent does not exist.
     *
     * NULL is not a violation — an optional relation is allowed to be empty.
     * **Zero is**, and in this estate that is the interesting case: columns
     * like `node_redirect_node_id` carry `DEFAULT 0` and use 0 the way a
     * modern schema uses NULL. The count therefore reports something real
     * even when the data is, in its own terms, perfectly fine — and that is
     * the finding, not a false alarm.
     */
    private function danglingRows(string $table, string $column, ForeignKey $fk): int
    {
        $q = $this->connection->platform()->quote(...);
        try {
            return (int) $this->connection->value(
                'SELECT COUNT(*) FROM ' . $q($table) . ' c'
                . ' LEFT JOIN ' . $q($fk->table) . ' p'
                . '   ON p.' . $q($fk->references) . ' = c.' . $q($column)
                . ' WHERE c.' . $q($column) . ' IS NOT NULL'
                . '   AND p.' . $q($fk->references) . ' IS NULL'
            );
        } catch (\Throwable) {
            // The parent table may not exist yet on a fresh install. Then
            // there is nothing to count and nothing to warn about.
            return 0;
        }
    }

    /**
     * The columns of the primary key, in order.
     *
     * @return list<string>
     */
    private function primaryColumnsOf(string $table): array
    {
        return array_column($this->connection->all(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [$table, 'PRIMARY'],
        ), 'COLUMN_NAME');
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->value(
            'SELECT COUNT(*) FROM ' . $this->connection->platform()->quote($table)
        );
    }

    /**
     * How many rows hold something worth keeping.
     *
     * The empty string counts as empty — an orphaned `varchar` that is `''`
     * in every row can go without anybody thinking about it, and that is the
     * difference between a confirmation somebody reads and one they click
     * away.
     *
     * But **only for text columns.** `col <> ''` against a DATETIME is error
     * 1525 in MySQL 8, not `false`: the empty string is not a date, and the
     * server refuses the comparison rather than inventing one. So the type
     * decides the test, and everything that is not text is judged by NULL
     * alone.
     */
    private function filledCount(string $table, string $column, string $dataType): int
    {
        $q = $this->connection->platform()->quote(...);
        $textLike = in_array(strtolower($dataType), [
            'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set',
        ], true);

        $where = $q($column) . ' IS NOT NULL'
            . ($textLike ? ' AND ' . $q($column) . " <> ''" : '');

        return (int) $this->connection->value(
            'SELECT COUNT(*) FROM ' . $q($table) . ' WHERE ' . $where
        );
    }
}
