<?php

declare(strict_types=1);

namespace Systopic\Db;

use ReflectionProperty;
use RuntimeException;
use Systopic\Db\Schema\Schema;
use Systopic\Db\Schema\Table;

/**
 * Writing — and only what has changed.
 *
 * The `set` hook is the only place a change comes into being. That is why the
 * object knows immediately what changed, without comparing snapshots, and why
 * a **partial object** can write as well: what was never loaded is not in
 * `$modified` and the UPDATE does not touch it.
 *
 * That is the difference to `record::updateRecord()`, which assembles the
 * field list at runtime out of eight naming patterns.
 *
 * Everything runs inside one transaction. Once the provisioner arrives, the
 * rule from section 10 applies: **nothing gets provisioned inside a
 * transaction**, because DDL commits implicitly in MySQL — the provisioner
 * rolls back, creates, and this commit runs once more.
 */
final class UnitOfWork
{
    /** @var list<TableRow> */
    private array $toPersist = [];

    /** @var list<TableRow> */
    private array $toRemove = [];

    /** @var list<string> what was executed last — for tests and debugging */
    private array $log = [];

    /** @var array<int, true> spl_object_id of the rows this commit inserted */
    private array $inserted = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?IdentityMap $identity = null,
        /**
         * The scope to report to after the commit: it throws away the query
         * results that read a written table and tells the listeners.
         */
        private readonly ?Scope $scope = null,
    ) {}

    public function persist(TableRow $row): self
    {
        if (!in_array($row, $this->toPersist, true)) {
            $this->toPersist[] = $row;
        }
        return $this;
    }

    /**
     * Persist and commit in one go, on the connection the application has open.
     *
     * The short form for the ordinary case: one row, or a handful that belong
     * together. Everything else — removing, inspecting the log, a connection of
     * one's own — goes through an instance.
     *
     * Deliberately here and not as `$row->save()`: a `TableRow` knows nothing
     * below itself, no connection and no reader, and that is what lets it be
     * built and checked without a database. Writing is this class's job.
     */
    public static function save(TableRow ...$rows): self
    {
        $uow = Scope::default()->uow();
        foreach ($rows as $row) {
            $uow->persist($row);
        }
        return $uow->commit();
    }

    public function remove(TableRow $row): self
    {
        if (!in_array($row, $this->toRemove, true)) {
            $this->toRemove[] = $row;
        }
        return $this;
    }

    /** @return list<string> the statements that were executed */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * Write everything inside one transaction.
     *
     * Order: create and change first, delete afterwards. Whoever creates one
     * row and removes another in the same go normally wants the new one to be
     * there by the time the old one leaves.
     */
    public function commit(): self
    {
        if ($this->toPersist === [] && $this->toRemove === []) {
            return $this;
        }

        $ordered = $this->ordered();

        $this->connection->transactional(function () use ($ordered) {
            foreach ($ordered as $row) {
                $this->isNew($row) ? $this->insert($row) : $this->update($row);
            }
            foreach ($this->toRemove as $row) {
                $this->delete($row);
            }
        });

        // Only after the transaction held: a result cache that forgets, a
        // listener that drops a row from its collection — neither may act on
        // a write that was rolled back. The changes are still on the rows
        // (`markSaved()` cleared what to write, not what changed); the scope
        // reads them and the rows forget them afterwards.
        //
        // Reported are the rows that CHANGED: new ones, and stored ones with
        // a value that differs. A field saved with the value it already had
        // (a form that saves on blur) is an UPDATE, but nothing changed — and
        // an observer that announces it to every open browser would have them
        // all re-render for nothing.
        $removed = $this->toRemove;
        $changed = array_values(array_filter($ordered, fn(TableRow $row): bool => isset($this->inserted[spl_object_id($row)]) || $row->changes() !== []));
        $this->toPersist = [];
        $this->toRemove  = [];
        $this->inserted  = [];
        $this->scope?->committed($changed, $removed);
        foreach ($ordered as $row) {
            $row->forgetChanges();
        }
        return $this;
    }

    // ------------------------------------------------------------------

    /**
     * The rows to write, targets before the rows that point at them.
     *
     * `$setting->node = new Node()` puts a node id into `cms_settings` that
     * does not exist yet — the target has to be inserted first, and until this
     * existed the order was whatever the caller happened to call `persist()`
     * in. The wrong order wrote NULL into the column without a word, because
     * `Extractor::toDb()` reads the id of a row that has not got one yet.
     *
     * So the order is derived instead of required, and a new row that nobody
     * registered comes along: reachable through a changed relation is reason
     * enough, since its absence would break the row that names it. That is
     * Doctrine's "persistence by reachability", narrowed to the one case where
     * it is not a guess.
     *
     * **Only new rows are pulled in.** A relation onto a row that is already
     * in the database is skipped entirely — otherwise saving one row would
     * quietly write another one that merely happened to hang off it. Changes
     * to a loaded row are written when it is persisted, and not before.
     *
     * @return list<TableRow>
     */
    private function ordered(): array
    {
        $ordered = [];
        $state   = [];   // spl_object_id => 1 = on the current path, 2 = placed
        foreach ($this->toPersist as $row) {
            $this->place($row, $ordered, $state, []);
        }
        return $ordered;
    }

    /**
     * Depth first: whatever this row needs goes in front of it.
     *
     * @param list<TableRow>     $ordered
     * @param array<int, int>    $state
     * @param list<string>       $path     how we got here, for the error
     */
    private function place(TableRow $row, array &$ordered, array &$state, array $path): void
    {
        $handle = spl_object_id($row);
        if (($state[$handle] ?? 0) === 2) {
            return;
        }
        if (($state[$handle] ?? 0) === 1) {
            // Two new rows that need each other's id. There is a way out of
            // this — insert one, update it afterwards — but it is a second
            // write nobody asked for, and a cycle among new rows is nearly
            // always a mistake in the model. So: say where it runs in circles.
            throw new RuntimeException(
                'the new rows point at each other in a circle: '
                . implode(' -> ', [...$path, $row::class])
                . ' — one of them has to be saved first'
            );
        }

        $state[$handle] = 1;
        foreach ($this->newReferencesOf($row) as $property => $target) {
            $this->place($target, $ordered, $state, [...$path, $row::class . '::$' . $property]);
        }
        $state[$handle] = 2;

        $ordered[] = $row;
    }

    /**
     * The rows this one points at that are not in the database yet.
     *
     * Out of `modified()`, and that is the whole reach of it: a relation onto
     * a fresh object can only have come from an assignment, and an assignment
     * is what `__set__()` records. What was loaded is in the database by
     * definition.
     *
     * @return array<string, TableRow> property name => row
     */
    private function newReferencesOf(TableRow $row): array
    {
        $refs = [];
        foreach ($row->modified() as $property => $value) {
            if ($value instanceof TableRow && $this->isNew($value)) {
                $refs[$property] = $value;
            }
        }
        return $refs;
    }

    /**
     * Is this row still to be created?
     *
     * With an AUTO_INCREMENT key the id answers it: NULL means the database
     * has never seen this row. A junction has no such column — its key is set
     * by whoever builds it, so the id would stay NULL forever and every save
     * would be an INSERT. There the row itself has to remember, and it does:
     * `persisted` is set by exactly two things, loading and saving.
     */
    private function isNew(TableRow $row): bool
    {
        return Schema::of($row::class)->keyIsId
            ? $row->id === null
            : !$row->isPersisted();
    }

    private function insert(TableRow $row): void
    {
        $table = Schema::of($row::class);

        // Without AUTO_INCREMENT nothing fills the key in afterwards, so it
        // has to be there beforehand. Doctrine has the same rule for composite
        // keys and puts it the same way: set the values before persist().
        if (!$table->keyIsId && Identity::valuesOf($row, $table) === null) {
            throw new RuntimeException(
                $row::class . ': the key (' . implode(', ', $table->keyProperties)
                . ') has to be set before saving — there is no AUTO_INCREMENT to fill it in'
            );
        }

        [$columns, $values] = $this->columnsOf($row, $table->propertyToColumn);

        if ($columns === []) {
            // A row with no values set at all: permissible when the table
            // consists of nothing but the key and default values.
            $sql = 'INSERT INTO ' . $this->q($table->name) . ' () VALUES ()';
            $this->connection->run($sql);
        } else {
            $sql = 'INSERT INTO ' . $this->q($table->name)
                . ' (' . implode(', ', array_map($this->q(...), $columns)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $this->connection->run($sql, array_values($values));
        }
        $this->log[] = $sql;
        $this->inserted[spl_object_id($row)] = true;

        if ($table->keyIsId) {
            $row->id = $this->connection->lastInsertId();
        }
        $row->markSaved();

        $key = Identity::of($row, $table);
        if ($this->identity !== null && $key !== null) {
            $this->identity->put($table->name, $key, $row);
        }
    }

    private function update(TableRow $row): void
    {
        if (!$row->isModified()) {
            return;   // nothing to do, and an empty SET would be a syntax error
        }
        if ($row->isReadOnly()) {
            // The query did not deliver the key. Without it there is no
            // identity — which shows here and not later as broken SQL.
            throw new RuntimeException(
                $row::class . ' is read-only: the query did not deliver the key'
            );
        }

        $table = Schema::of($row::class);
        $key = Identity::valuesOf($row, $table);
        if ($key === null) {
            throw new RuntimeException(
                $row::class . ' has no complete key — there is nothing to update'
            );
        }

        [$columns, $values] = $this->columnsOf($row, $table->propertyToColumn);
        $this->refuseKeyChange($row, $table, $values);
        if ($columns === []) {
            return;
        }

        $sql = 'UPDATE ' . $this->q($table->name) . ' SET '
            . implode(', ', array_map(fn(string $c): string => $this->q($c) . ' = ?', $columns))
            . ' WHERE ' . $this->where($table);

        $this->connection->run($sql, [...array_values($values), ...$key]);
        $this->log[] = $sql;
        $row->markSaved();
    }

    private function delete(TableRow $row): void
    {
        $table = Schema::of($row::class);
        $key = Identity::valuesOf($row, $table);
        if ($key === null) {
            return;   // was never in the database
        }
        $sql = 'DELETE FROM ' . $this->q($table->name)
            . ' WHERE ' . $this->where($table);
        $this->connection->run($sql, $key);
        $this->log[] = $sql;
    }

    /**
     * Writing a key column means a different row, not a changed one.
     *
     * Only reachable for a declared key, because `$id` is not a column and
     * never lands in `$modified`. A junction is different: its key properties
     * are ordinary relations with an ordinary set hook, so
     * `$link->tag = $other` records like any other change — and an UPDATE
     * built from it would move this row onto another row's identity, quietly,
     * and possibly onto one that already exists.
     *
     * Remove the one and create the other. That is what actually happened.
     *
     * @param array<string, mixed> $values column => value
     */
    private function refuseKeyChange(TableRow $row, Table $table, array $values): void
    {
        foreach ($table->key as $column) {
            if (!array_key_exists($column, $values)) {
                continue;
            }
            $property = array_search($column, $table->propertyToColumn, true) ?: $column;
            throw new RuntimeException(
                $row::class . ": \$$property is part of the key — assigning it means a "
                . 'different row. Remove this one and create that one.'
            );
        }
    }

    /** `WHERE a = ? AND b = ?` — one term per key column, in key order. */
    private function where(Table $table): string
    {
        return implode(' AND ', array_map(
            fn(string $c): string => $this->q($c) . ' = ?',
            $table->key,
        ));
    }

    /**
     * Translate the changed properties into columns and raw values.
     *
     * `Extractor::toDb()` runs here: a `DateTimeImmutable` becomes a string, a
     * backed enum its value, a type class whatever its `toDb()` returns — and
     * a relation the id of the target object, not the object.
     *
     * @param array<string, string> $propertyToColumn
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function columnsOf(TableRow $row, array $propertyToColumn): array
    {
        $columns = [];
        $values   = [];
        foreach ($row->modified() as $property => $value) {
            $column = $propertyToColumn[$property] ?? null;
            if ($column === null) {
                continue;   // not a column, a virtual property for instance
            }
            $columns[]        = $column;
            $values[$column]   = Extractor::toDb($value);
        }
        return [$columns, $values];
    }

    private function q(string $identifier): string
    {
        return $this->connection->platform()->quote($identifier);
    }
}
