<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use PDOException;
use RuntimeException;
use Systopic\Db\Connection;

/**
 * Provide structure — at the error, not by comparison.
 *
 * The success path does **nothing**. No fingerprint, no proactive diff, no
 * scan of a migrations directory: `try/catch` costs 3.5 ns per run, against a
 * 70 µs roundtrip that is 0.005 %. Only when a statement fails does this class
 * look at why, and only then does it act.
 *
 * Five codes can be interpreted, all of them **lossless**:
 *
 *   1146  table missing         CREATE TABLE from the declaration
 *   1054  column missing        ADD COLUMN from the declaration
 *   1406  value too long        MODIFY to the declared width
 *   1264  value out of range    MODIFY to the declared type
 *   1060  column already there  nothing — just retry
 *
 * The opposite direction is deliberately missing. Narrowing, dropping and
 * renaming throw no error and are therefore never touched automatically; they
 * belong to `Audit` and to migrations.
 *
 * **The rule that separates widening from throwing:** widen only when the
 * declaration contradicts the column; throw when the declaration fits and the
 * value still does not go in. A `VARCHAR(255)` column declared as
 * `VARCHAR(255)` that refuses a 300-character value is not a schema problem —
 * it is a bug in the caller, and hiding it would be worse than the error.
 *
 * 1060 is the concurrency case and solves itself: two requests see the same
 * missing column, both fire `ALTER`, one gets `Duplicate column name`. That
 * means "the column is there" — retry, done. **No `GET_LOCK` needed.**
 *
 * The name is `Provisioner` and not `Healer`: the database is not ill, it is
 * not finished yet.
 */
final class Provisioner
{
    /** @var list<string> what was done, in order — for the log and for tests */
    private array $log = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?Migrations $migrations = null,
    ) {}

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * Try to interpret a failed statement and repair the cause.
     *
     * @param  string|null $origin  the TableRow class the query was built
     *                              from, when it is known. The error message
     *                              names the column but not the table, so for
     *                              a prefixless table this is the only way to
     *                              resolve it.
     * @return bool  whether something was repaired and the statement is worth
     *               retrying
     */
    public function provide(PDOException $e, ?string $origin = null): bool
    {
        $code = self::codeOf($e);
        $message = $e->getMessage();

        return match ($code) {
            1146 => $this->createTable($message),
            1054 => $this->addColumn($message, $origin),
            1406, 1264 => $this->widenColumn($code, $message, $origin),
            // "Duplicate column name": somebody else was faster. Nothing to do
            // but repeat — that is the whole concurrency handling.
            1060 => $this->note('1060 duplicate column — another request was faster, retrying'),
            default => false,
        };
    }

    // --------------------------------------------------------------- 1146

    /**
     * `Table 'db.cms_tags' doesn't exist`
     *
     * Greedy up to the **last** dot: a database may have a dot in its name —
     * this project runs on `galerie-lattemann.de`, and splitting at the first
     * dot yielded `de.cms_provision_probe` as the table.
     */
    private function createTable(string $message): bool
    {
        if (!preg_match("~Table '(?:.*\.)?([^.']+)' doesn't exist~", $message, $m)) {
            return false;
        }
        $table = $this->tableFor($m[1]);
        if ($table === null) {
            return false;   // not ours — hand the error on
        }

        // Deliberately **without** foreign key constraints: a CREATE TABLE
        // with an FK onto a table that does not exist yet fails with 1824,
        // and during provisioning that is the normal case. The relation lives
        // in the declaration, where it counts for the ORM; the constraints are
        // `Audit`'s business.
        if ($this->alreadyTried('CREATE TABLE ' . $table->name)) {
            return false;
        }

        $sql = $this->connection->platform()->createTable($table, withForeignKeys: false);
        $this->connection->exec($sql);
        $this->note('CREATE TABLE ' . $table->name);

        $this->migrations?->runFor($table->name, $this->connection, $this->log);
        return true;
    }

    // --------------------------------------------------------------- 1054

    /** `Unknown column 'page_subtitle' in 'field list'` */
    private function addColumn(string $message, ?string $origin): bool
    {
        if (!preg_match("~Unknown column '([^']+)'~", $message, $m)) {
            return false;
        }
        // In a JOIN the alias is prefixed: `n.node_subtitle`.
        $column = str_contains($m[1], '.') ? substr(strrchr($m[1], '.'), 1) : $m[1];

        $table = $this->tableForColumn($column, $origin);
        if ($table === null || !isset($table->columns[$column])) {
            // The column appears in no declaration — then this is not a
            // missing column but a typo, and the error belongs to the caller.
            return false;
        }

        if ($this->alreadyTried('ADD COLUMN ' . $table->name . '.' . $column)) {
            return false;
        }

        $sql = $this->connection->platform()->addColumn($table, $column);
        $this->connection->exec($sql);
        $this->note('ADD COLUMN ' . $table->name . '.' . $column);

        // A column that arrives with an index declared on it gets the index
        // too. Otherwise a table repaired at the error would differ from one
        // freshly created from the same class — and that difference would be
        // invisible, because a missing index never raises an error.
        //
        // Deliberately not fatal: the column is there and the query can go on.
        // An index is a matter of speed, and losing the whole repair over it
        // would be the wrong trade.
        if ($table->columns[$column]->index !== null || isset($table->foreignKeys[$column])) {
            try {
                $this->connection->exec($this->connection->platform()->addIndex($table, $column));
                $this->note('ADD INDEX ' . $table->name . '.' . $column);
            } catch (\Throwable $e) {
                $this->note('INDEX failed ' . $table->name . '.' . $column . ': ' . $e->getMessage());
            }
        }

        $this->migrations?->runFor($table->name . '.' . $column, $this->connection, $this->log);
        return true;
    }

    // -------------------------------------------------------- 1406 / 1264

    /**
     * `Data too long for column 'page_label' at row 1`
     * `Out of range value for column 'node_ordering' at row 1`
     *
     * This is the part a proactive diff could not do better: **a type
     * deviation is invisible while reading.** A `VARCHAR(40)` column declared
     * as `VARCHAR(255)` hands out its values on `SELECT` without complaint —
     * it only speaks up when a value that is too long wants in. And exactly
     * then widening is lossless and right.
     */
    private function widenColumn(int $code, string $message, ?string $origin): bool
    {
        if (!preg_match("~for column '([^']+)'~", $message, $m)) {
            return false;
        }
        $column = str_contains($m[1], '.') ? substr(strrchr($m[1], '.'), 1) : $m[1];

        $table = $this->tableForColumn($column, $origin);
        if ($table === null || !isset($table->columns[$column])) {
            return false;
        }

        // The rule: widen only when the declaration contradicts the column. If
        // the database already matches the declaration, the value simply does
        // not fit — and that is the caller's bug, not the schema's.
        //
        // Compared without the DEFAULT, because that is not structure. With it
        // a declared `DEFAULT ''` that the column does not carry looked like a
        // type deviation, and the provisioner widened in a loop until the
        // attempt counter stopped it.
        $declared = $this->connection->platform()->renderColumn($table->columns[$column]);
        $actual   = $this->actualColumn($table->name, $column);
        if ($actual !== null
            && Column::comparable($actual) === Column::comparable($declared)) {
            $this->note(sprintf(
                '%d %s.%s — the declaration already matches the column, passing the error on',
                $code, $table->name, $column,
            ));
            return false;
        }

        if ($this->alreadyTried('MODIFY ' . $table->name . '.' . $column)) {
            return false;
        }

        $sql = $this->connection->platform()->modifyColumn($table, $column);
        $this->connection->exec($sql);
        $this->note('MODIFY ' . $table->name . '.' . $column);

        $this->migrations?->runFor($table->name . '.' . $column, $this->connection, $this->log, Migrations::WIDEN);
        return true;
    }

    /**
     * The same repair, a second time, is never the answer.
     *
     * The comparison in `Column::comparable()` is supposed to round-trip, so
     * that a repair which changed nothing is recognised as such on the next
     * attempt and the error travels on. This is the net underneath: if
     * something the normalisation does not know about ever slips through, the
     * provisioner still stops after one try instead of rewriting the same
     * column until the attempt counter runs out.
     *
     * It says nothing about whether the repair worked — only that it has been
     * made. A repair that worked leaves no second error to answer.
     *
     * @var array<string, true>
     */
    private array $tried = [];

    /**
     * A new statement begins — forget what was repaired for the previous one.
     *
     * The guard is meant to stop a repair being repeated **while one query
     * keeps failing**, not to remember for ever. A later error on the same
     * column is a different situation and deserves its own attempt; without
     * this reset the second one would be refused on the strength of the
     * first, which is how the probe for the migration hook stopped working.
     */
    public function beginCycle(): void
    {
        $this->tried = [];
    }

    private function alreadyTried(string $what): bool
    {
        if (isset($this->tried[$what])) {
            $this->note($what . ' — already attempted once, passing the error on');
            return true;
        }
        $this->tried[$what] = true;
        return false;
    }

    // -------------------------------------------------------------- lookup

    private function tableFor(string $name): ?Table
    {
        $class = Registry::classFor($name);
        return $class === null ? null : Schema::of($class);
    }

    /**
     * Which table does a column belong to?
     *
     * Two ways, both cheap. **The prefix suffices** for the legacy tables:
     * `page_subtitle` belongs to exactly one registered class. **The query
     * knows it** for prefixless new tables — the builder assembled it from a
     * TableRow class and hands that class over as `origin`.
     */
    private function tableForColumn(string $column, ?string $origin): ?Table
    {
        // The origin is a TableRow class when a model loads itself, the .sql
        // file when a query does (Scope::rows()) — only the first names a table.
        if ($origin !== null && is_subclass_of($origin, \Systopic\Db\TableRow::class)) {
            $table = Schema::of($origin);
            if (isset($table->columns[$column])) {
                return $table;
            }
        }
        foreach (Registry::all() as $class) {
            $table = Schema::of($class);
            if (isset($table->columns[$column])) {
                return $table;
            }
        }
        return null;
    }

    /** The column as it currently stands in the database, rendered like a declared one. */
    private function actualColumn(string $table, string $column): ?string
    {
        $row = $this->connection->one(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        if ($row === null) {
            return null;
        }
        return $row['COLUMN_TYPE'] . ($row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL');
    }

    private function note(string $line): bool
    {
        $this->log[] = $line;
        return true;
    }

    private static function codeOf(PDOException $e): int
    {
        // The driver code sits in errorInfo[1]; getCode() carries the SQLSTATE
        // and is a string.
        return (int) ($e->errorInfo[1] ?? 0);
    }
}
