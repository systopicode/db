<?php

declare(strict_types=1);

namespace Systopic\Db\Platform;

use RuntimeException;
use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\ForeignKey;
use Systopic\Db\Schema\Index;
use Systopic\Db\Schema\Kind;
use Systopic\Db\Schema\Table;

final class MySql implements Platform
{
    public function __construct(
        private readonly string $engine  = 'InnoDB',
        private readonly string $charset = 'utf8mb4',
        private readonly string $collate = 'utf8mb4_unicode_ci',
    ) {}

    public function name(): string
    {
        return 'mysql';
    }

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function renderColumn(Column $c): string
    {
        $sql = match ($c->kind) {
            Kind::Int      => 'int',
            Kind::BigInt   => 'bigint',
            Kind::Float    => 'double',
            Kind::Decimal  => 'decimal(' . ($c->length ?? 10) . ',' . ($c->scale ?? 2) . ')',
            Kind::Bool     => 'tinyint(1)',
            Kind::String   => $c->enumCases
                ? 'enum(' . implode(',', array_map($this->literal(...), $c->enumCases)) . ')'
                : 'varchar(' . ($c->length ?? 255) . ')',
            Kind::Text     => match (true) {
                $c->length === null        => 'longtext',
                $c->length <= 65535        => 'text',
                $c->length <= 16777215     => 'mediumtext',
                default                    => 'longtext',
            },
            Kind::Json     => 'json',
            Kind::Date     => 'date',
            Kind::DateTime => 'datetime',
            Kind::Time     => 'time',
            // With a declared length it is a fixed-ish field (an IP, a hash),
            // without one it is a blob. `varbinary(16)` and `blob` are not the
            // same column: the first lives in the row, the second can be
            // pushed off-page.
            Kind::Binary   => $c->length === null ? 'blob' : 'varbinary(' . $c->length . ')',
        };

        if ($c->unsigned && in_array($c->kind, [Kind::Int, Kind::BigInt, Kind::Decimal, Kind::Float], true)) {
            $sql .= ' unsigned';
        }
        $sql .= $c->nullable ? ' NULL' : ' NOT NULL';

        if ($c->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        } elseif ($c->default !== null) {
            $sql .= ' DEFAULT ' . $this->literal($c->default);
        }

        return $sql;
    }

    public function createTable(Table $t, bool $withForeignKeys = true): string
    {
        $lines = [];
        foreach ($t->columns as $name => $c) {
            $lines[] = '  ' . $this->quote($name) . ' ' . $this->renderColumn($c);
        }
        $lines[] = '  PRIMARY KEY ('
            . implode(', ', array_map($this->quote(...), $t->key)) . ')';

        foreach ($t->columns as $name => $c) {
            if ($name === ($t->key[0] ?? null) || $c->index === null) {
                continue;
            }
            $lines[] = match ($c->index) {
                Index::Unique   => '  UNIQUE KEY ' . $this->quote('uk_' . $name) . ' (' . $this->quote($name) . ')',
                Index::Fulltext => '  FULLTEXT KEY ' . $this->quote('ft_' . $name) . ' (' . $this->quote($name) . ')',
                Index::Plain    => '  KEY ' . $this->quote($name) . ' (' . $this->quote($name) . ')',
            };
        }

        // Every foreign key column gets its index, stated rather than assumed.
        //
        // InnoDB would create one by itself for the constraint, so on MySQL
        // this changes nothing — but Postgres does **not**, and an unindexed
        // foreign key there means a sequential scan on every join and on every
        // delete in the parent table. Writing it down keeps the declaration
        // portable and keeps the audit able to see it.
        foreach (array_keys($t->foreignKeys) as $name) {
            // The leftmost key column needs none: a composite primary key is
            // an index on its own prefix, so `KEY (tin_tag_id)` next to
            // `PRIMARY KEY (tin_tag_id, tin_node_id)` would be a second copy
            // of the same thing. The right-hand column is the one that has no
            // index and is exactly the one a reverse lookup reads.
            if ($name === ($t->key[0] ?? null)) {
                continue;
            }
            if (isset($t->columns[$name]) && $t->columns[$name]->index === null) {
                $lines[] = '  KEY ' . $this->quote($name) . ' (' . $this->quote($name) . ')';
            }
        }

        if ($withForeignKeys) {
            foreach ($t->foreignKeys as $fk) {
                $lines[] = '  CONSTRAINT ' . $this->quote('fk_' . $t->name . '_' . $fk->column)
                    . ' FOREIGN KEY (' . $this->quote($fk->column) . ')'
                    . ' REFERENCES ' . $this->quote($fk->table) . ' (' . $this->quote($fk->references) . ')';
            }
        }

        return 'CREATE TABLE ' . $this->quote($t->name) . " (\n"
            . implode(",\n", $lines) . "\n"
            . ') ENGINE=' . $this->engine
            . ' DEFAULT CHARSET=' . $this->charset
            . ' COLLATE=' . $this->collate;
    }

    public function addColumn(Table $t, string $column): string
    {
        $c = $t->columns[$column] ?? throw new RuntimeException(
            "column `$column` is not declared in {$t->class}"
        );
        return 'ALTER TABLE ' . $this->quote($t->name)
            . ' ADD COLUMN ' . $this->quote($column) . ' ' . $this->renderColumn($c);
    }

    public function modifyColumn(Table $t, string $column): string
    {
        $c = $t->columns[$column] ?? throw new RuntimeException(
            "column `$column` is not declared in {$t->class}"
        );
        return 'ALTER TABLE ' . $this->quote($t->name)
            . ' MODIFY ' . $this->quote($column) . ' ' . $this->renderColumn($c);
    }

    /**
     * A plain index is named after its column.
     *
     * Not a taste decision — it is what the estate already does:
     * `page_node_id`, `tag_taggroup_id`, `media_node_id` are all indexes named
     * exactly like the column they sit on. Unique and fulltext get a prefix,
     * because there the name has to say what kind of promise it makes.
     */
    public function indexName(Table $t, string $column): string
    {
        return match ($t->columns[$column]?->index ?? null) {
            Index::Unique   => 'uk_' . $column,
            Index::Fulltext => 'ft_' . $column,
            default         => $column,
        };
    }

    public function addIndex(Table $t, string $column): string
    {
        $c = $t->columns[$column] ?? throw new RuntimeException(
            "column `$column` is not declared in {$t->class}"
        );
        $kind = match ($c->index) {
            Index::Unique   => 'UNIQUE INDEX',
            Index::Fulltext => 'FULLTEXT INDEX',
            // No declared index and we still get here: it is a foreign key
            // column, and those are indexed whether anybody said so or not.
            default         => 'INDEX',
        };
        return 'ALTER TABLE ' . $this->quote($t->name)
            . ' ADD ' . $kind . ' ' . $this->quote($this->indexName($t, $column))
            . ' (' . $this->quote($column) . ')';
    }

    public function dropIndex(string $table, string $index): string
    {
        return 'DROP INDEX ' . $this->quote($index) . ' ON ' . $this->quote($table);
    }

    public function foreignKeyChecks(bool $on): string
    {
        return 'SET FOREIGN_KEY_CHECKS = ' . ($on ? '1' : '0');
    }

    /**
     * A multi-table UPDATE with a LEFT JOIN, and not the obvious subquery.
     *
     * `WHERE col NOT IN (SELECT id FROM t)` is error 1093 — "You can't specify
     * target table for update in FROM clause" — whenever the relation points
     * at its own table. The join form is allowed there, and is the faster one
     * anyway.
     */
    public function clearOrphans(string $table, string $column, ForeignKey $foreignKey): string
    {
        $child  = $this->quote($table);
        $parent = $this->quote($foreignKey->table);
        $ref    = $this->quote($foreignKey->references);
        $col    = $this->quote($column);

        return "UPDATE $child c"
            . " LEFT JOIN $parent p ON p.$ref = c.$col"
            . " SET c.$col = NULL"
            . " WHERE c.$col IS NOT NULL AND p.$ref IS NULL";
    }

    private function literal(mixed $v): string
    {
        return match (true) {
            is_bool($v)            => $v ? '1' : '0',
            is_int($v), is_float($v) => (string) $v,
            default                => "'" . str_replace("'", "''", (string) $v) . "'",
        };
    }
}
