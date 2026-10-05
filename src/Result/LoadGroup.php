<?php

declare(strict_types=1);

namespace Systopic\Db\Result;

use ReflectionProperty;
use RuntimeException;
use Systopic\Db\Connection;
use Systopic\Db\Extractor;
use Systopic\Db\Schema\Schema;
use Systopic\Db\TableRow;

/**
 * Every object of one execution — and the place where missing values get
 * fetched.
 *
 * A query delivers 68 columns out of three tables; which of them anybody
 * touches is unknowable in advance. Instead of loading everything or refusing
 * everything, the rule is: what is there is there, and what is missing gets
 * fetched on access — for **every** object of the group at once.
 *
 * That is exactly the difference to the N+1 problem. Not "one query per
 * object" but "one query for all objects" — and that query brings the
 * **whole** rows (`SELECT *`). Fetching only the column asked for cost one
 * statement per column: a page list that read `startPublishing`, then
 * `endPublishing`, then `text01` … sent the same `WHERE id IN (…)` once per
 * column (measured 2026-10-02: up to 13 per request). Once the rows are
 * there, every further column is free.
 */
final class LoadGroup
{
    /** @var array<class-string, list<TableRow>> */
    private array $rows = [];

    /** @var array<class-string, true> classes whose rows were fetched whole (Group) */
    private array $completedClasses = [];

    /** @var array<int, true> spl_object_id => fetched whole (Single) */
    private array $completedRows = [];

    private int $queries = 0;

    public function __construct(
        private readonly ResultMap $map,
        private readonly Connection $connection,
        private readonly Completion $policy = Completion::Group,
    ) {}

    public function add(TableRow $row): void
    {
        $this->rows[$row::class][] = $row;
    }

    /** @return list<TableRow> */
    public function of(string $class): array
    {
        return $this->rows[$class] ?? [];
    }

    public function map(): ResultMap
    {
        return $this->map;
    }

    public function policy(): Completion
    {
        return $this->policy;
    }

    /** How many extra queries has this group cost? */
    public function queryCount(): int
    {
        return $this->queries;
    }

    /**
     * Fetch a property the query did not deliver.
     *
     * The question "is it missing?" goes to the **plan**, never to the
     * objects. Measured: `isInitialized()` on a lazy ghost wakes it, and a
     * scan over 100 ghosts materialised all 100. The plan knows it anyway.
     *
     * @return bool whether anything was fetched
     */
    public function complete(TableRow $row, string $property): bool
    {
        $class = $row::class;

        if ($this->map->delivers($class, $property)
            || isset($this->completedClasses[$class])
            || isset($this->completedRows[spl_object_id($row)])) {
            return false;   // already there
        }

        $table = Schema::of($class);
        $column = $table->propertyToColumn[$property] ?? null;
        if ($column === null) {
            throw new RuntimeException("$class has no column for \$$property");
        }
        // Completion fetches `WHERE key IN (…)`, and a list of tuples is not
        // that. It has never been needed either: a junction is two to five
        // columns, so a query that reads it at all reads all of it. Saying so
        // here is better than letting `$table->primary` throw one layer down
        // with a message about something the caller never asked for.
        if ($table->isComposite) {
            throw new RuntimeException(
                "\$$property was not in the query, and $class has a composite key — "
                . 'such a row is fetched whole or not at all'
            );
        }

        if ($this->policy === Completion::Never) {
            throw new RuntimeException(
                "\$$property was not in the query — Completion::Never forbids fetching it. "
                . 'Either take the column into the query or change the policy.'
            );
        }

        $targets = $this->policy === Completion::Group ? ($this->rows[$class] ?? []) : [$row];
        $ids = [];
        foreach ($targets as $candidate) {
            $id = self::idOf($candidate);
            if ($id !== null) {
                $ids[$id] = $candidate;
            }
        }
        if ($ids === []) {
            return false;
        }

        $list = implode(',', array_map('intval', array_keys($ids)));
        $sql = 'SELECT * FROM ' . $this->quote($table->name)
            . ' WHERE ' . $this->quote($table->primary) . " IN ($list)";

        $this->queries++;
        foreach ($this->connection->all($sql) as $data) {
            $target = $ids[(int) $data[$table->primary]] ?? null;
            if ($target !== null) {
                $this->fillRow($target, $class, $table, $data);
            }
        }

        // With Group the class counts as done afterwards; with Single only
        // the one object was filled.
        if ($this->policy === Completion::Group) {
            $this->completedClasses[$class] = true;
        } else {
            $this->completedRows[spl_object_id($row)] = true;
        }
        return true;
    }

    /**
     * Fill everything the row does not hold yet from its whole database row.
     *
     * Only gaps: a value the query delivered or the application assigned
     * stays — loading must not overwrite a change (the rule of
     * `RowReader::fillGaps()`). Foreign keys go into the ref ids, as a first
     * read files them.
     *
     * A sleeping ghost is filled without waking it, and only with columns
     * the plan did not deliver (its initialiser brings those). Its foreign
     * keys are left alone: the initialiser replaces the ref ids anyway, and
     * a relation read on it later finds its way through the row's own
     * fallback (`Resolver::complete()`).
     *
     * @param array<string, mixed> $data
     */
    private function fillRow(TableRow $row, string $class, \Systopic\Db\Schema\Table $table, array $data): void
    {
        $sleeping = (new \ReflectionClass($row))->isUninitializedLazyObject($row);
        $refs = [];
        foreach ($table->propertyToColumn as $property => $column) {
            if ($property === 'id' || !array_key_exists($column, $data)) {
                continue;
            }
            if ($sleeping ? $this->map->delivers($class, $property) : $row->knows($property)) {
                continue;
            }
            $raw = $data[$column];
            if (isset($table->foreignKeys[$column])) {
                if ($sleeping || $row->refId($property) !== null) {
                    continue;
                }
                if ($raw !== null && (int) $raw > 0) {
                    $refs[$property] = (int) $raw;
                } else {
                    self::fill($row, $property, null, false);   // points nowhere — a fact
                }
                $row->markKnown($property);
                continue;
            }
            self::fill($row, $property, Extractor::fromDb($class, $property, $raw), $sleeping);
        }
        if ($refs !== []) {
            $row->addRefIds($refs);
        }
    }

    /**
     * Write past the set hook — loading is not a change.
     *
     * `setRawValueWithoutLazyInitialization()` instead of `setRawValue()`: it
     * fills a still sleeping ghost without waking it. That was exactly the
     * trap in the first prototype.
     */
    private static function fill(TableRow $row, string $property, mixed $value, bool $sleeping): void
    {
        $rp = new ReflectionProperty($row::class, $property);
        if ($sleeping) {
            $rp->setRawValueWithoutLazyInitialization($row, $value);
            return;
        }
        $rp->setRawValue($row, $value);
        $row->markKnown($property);
    }

    private static function idOf(TableRow $row): ?int
    {
        $rp = new ReflectionProperty($row::class, 'id');
        return $rp->isInitialized($row) ? $rp->getValue($row) : null;
    }

    private function quote(string $identifier): string
    {
        return $this->connection->platform()->quote($identifier);
    }
}
