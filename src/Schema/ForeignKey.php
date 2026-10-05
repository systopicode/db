<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/**
 * A relation as it falls out of the declaration.
 *
 * It does not come from an attribute but from the fact that the type of a
 * property is a `TableRow` class:
 *
 *     public ?Node $parentNode { … }   ->   node_parent_node_id -> cms_nodes.node_id
 *
 * Whether that becomes a constraint in the database is a second question: the
 * provisioner creates tables without constraints, because an FK onto a table
 * that does not exist yet fails with 1824. For the ORM what counts is the
 * statement here, not the entry in `information_schema`.
 */
final class ForeignKey
{
    public function __construct(
        /** Column in this table. */
        public readonly string $column,
        /** Target table. */
        public readonly string $table,
        /** Column in the target table, as a rule its primary key. */
        public readonly string $references,
        /** The TableRow class behind it — the row reader needs it. */
        public readonly string $class,
    ) {}
}
