<?php

declare(strict_types=1);

namespace Systopic\Db\Platform;

use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\ForeignKey;
use Systopic\Db\Schema\Table;

/**
 * The one place where SQL dialect lives.
 *
 * That this interface exists is why `Kind` and `Column` must know no SQL: the
 * same declaration is meant to run against MySQL and against Postgres. The
 * provisioner uses `renderColumn()` for exactly one column, `db audit` and
 * `db provision` for a whole schema.
 */
interface Platform
{
    public function name(): string;

    /** Quote an identifier — backticks, double quotes, depending on the engine. */
    public function quote(string $identifier): string;

    /** The type part of a column definition, without the name. */
    public function renderColumn(Column $column): string;

    /** `CREATE TABLE` for a whole table. */
    public function createTable(Table $table, bool $withForeignKeys = true): string;

    /** `ALTER TABLE … ADD COLUMN` for exactly one column. */
    public function addColumn(Table $table, string $column): string;

    /** `ALTER TABLE … MODIFY/ALTER COLUMN` — widening a type after the fact. */
    public function modifyColumn(Table $table, string $column): string;

    /**
     * The name this platform gives the index on one column.
     *
     * On the interface and not private, because the audit has to compare what
     * the database calls an index with what we would have called it. Two
     * places deriving the same name from the same rule is how they drift
     * apart.
     */
    public function indexName(Table $table, string $column): string;

    /** Create the index one column declares — or the one its relation needs. */
    public function addIndex(Table $table, string $column): string;

    /** Drop an index by name. */
    public function dropIndex(string $table, string $index): string;

    /**
     * Turn foreign key checking off and on again.
     *
     * The one thing it is good for: adding a foreign key to a table that has
     * grown without one. Existing rows are then **not** validated, so the
     * constraint is created even where the data contradicts it, and only
     * future writes are checked.
     *
     * That is a deliberate trade and not a repair — the offending rows stay,
     * and the day somebody touches the parent row the constraint finally
     * speaks up. It must therefore never be the default.
     *
     * It does **not** help with a unique key: a duplicate is a duplicate, and
     * there is no switch that makes two identical values fit through one.
     */
    public function foreignKeyChecks(bool $on): string;

    /**
     * Set every reference that points at nothing to NULL.
     *
     * The step before adding a foreign key to a table that grew without one.
     * A child row naming a parent that does not exist holds no information —
     * the id has long stopped meaning anything — so clearing it loses the
     * shape of a mistake, not a fact.
     *
     * It is a dialect question, which is why it lives here: the obvious
     * `col NOT IN (SELECT id FROM t)` is error 1093 in MySQL as soon as the
     * relation points at its own table, and `cms_nodes.node_parent_node_id`
     * does exactly that.
     */
    public function clearOrphans(string $table, string $column, ForeignKey $foreignKey): string;
}
