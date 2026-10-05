<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use RuntimeException;

/**
 * A table, derived from a TableRow class.
 *
 * The counterpart to the old model: there the database was the reference and
 * `dbSchema` read it out. Here the class is the reference and the database is
 * the image of it.
 *
 * **The key is a tuple, not a column.** Everything used to rest on a single
 * `<prefix>_id`, and that excluded eight tables of this installation from the
 * model — every junction has `(a_id, b_id)`. Measured in `pwk_dev`, four of
 * nine of them carry payload as well (`cib_rating` is filled in 1117 of 1118
 * rows), so they are rows in every sense the word has here.
 *
 * For the twenty classes with a single key nothing changes: `$key` holds one
 * column, `$primary` returns it, and `TableRow::$id` stays what it was.
 */
final class Table
{
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        /** @var list<string> the key columns, in key order */
        public readonly array $key,
        /** @var array<string, Column> column name => Column, in declaration order */
        public readonly array $columns,
        /** @var array<string, ForeignKey> column name => ForeignKey */
        public readonly array $foreignKeys = [],
        /** @var array<string, string> property name => column name */
        public readonly array $propertyToColumn = [],
        /** Column name prefix without the underscore, '' for prefixless tables. */
        public readonly string $prefix = '',
        /**
         * @var list<string> the key properties, in the same order as `$key`
         *
         * For a single key that is `['id']` — the one property this layer does
         * not make the class declare. For a junction they are the relations
         * named in `const KEY`.
         */
        public readonly array $keyProperties = ['id'],
    ) {}

    /** More than one column in the key — a junction, as a rule. */
    public bool $isComposite {
        get => count($this->key) > 1;
    }

    /**
     * Is the key the `$id` this layer invents, rather than declared columns?
     *
     * The question the lazy ghost hangs on: its identity trick is to set `$id`
     * without waking the object, and there is no counterpart to that for a key
     * made of ordinary properties.
     */
    public bool $keyIsId {
        get => $this->keyProperties === ['id'];
    }

    /**
     * The one key column, or NULL when there are several.
     *
     * For everything that has to ask before it acts. `$primary` is for
     * everything that cannot mean a tuple in the first place.
     */
    public ?string $singleKey {
        get => count($this->key) === 1 ? $this->key[0] : null;
    }

    /**
     * The one key column — and an error when there is not exactly one.
     *
     * Three callers genuinely cannot mean a tuple: `Resolver` follows a
     * foreign key, which points at a single key by definition; `LoadGroup`
     * fetches a missing column `WHERE key IN (…)`; and `Extractor` turns a
     * related object back into its id. Throwing here says where the limit is,
     * instead of letting a composite table slip through and produce SQL that
     * silently matches the wrong rows.
     */
    public string $primary {
        get => $this->singleKey ?? throw new RuntimeException(
            $this->name . ' has a composite key (' . implode(', ', $this->key)
            . ') — this caller can only work with a single one'
        );
    }

    public function column(string $property): ?Column
    {
        $name = $this->propertyToColumn[$property] ?? null;
        return $name !== null ? ($this->columns[$name] ?? null) : null;
    }

    /** column name => property name. The row reader reads in this direction. */
    public function columnToProperty(): array
    {
        return array_flip($this->propertyToColumn);
    }

    /** Is this column part of the primary key? */
    public function isKeyColumn(string $column): bool
    {
        return in_array($column, $this->key, true);
    }

    /** The column list for a SELECT — from the declaration, not from `*`. */
    public function selectList(): string
    {
        return implode(', ', array_map(
            static fn(string $c): string => '`' . $c . '`',
            array_keys($this->columns)
        ));
    }
}
