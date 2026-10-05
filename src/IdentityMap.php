<?php

declare(strict_types=1);

namespace Systopic\Db;

/**
 * One row, one object.
 *
 * Why that is not a luxury is in the measurement: a single call of
 * `cms/pages/?id=19` sends **the same `pages.sql` 24 times** to the database,
 * a front-end call eight times. Without an identity map that would produce 24
 * sets of objects as well — each with its own set of changes, overwriting each
 * other on save.
 *
 * The key is (table, identity), not (class, identity): two classes on the same
 * table would be two identities for one row, and that is exactly what the
 * registry forbids.
 *
 * The identity is a **string**, built by `Identity::hash()`. For a single key
 * that is the id itself, so `$map->get('cms_pages', '17')` reads as it always
 * did; for a junction it is the key tuple. The map does not care which — it
 * never takes the string apart.
 */
final class IdentityMap
{
    /** @var array<string, array<string, TableRow>> table => identity => object */
    private array $rows = [];

    public function get(string $table, string $key): ?TableRow
    {
        return $this->rows[$table][$key] ?? null;
    }

    public function put(string $table, string $key, TableRow $row): TableRow
    {
        return $this->rows[$table][$key] ??= $row;
    }

    public function has(string $table, string $key): bool
    {
        return isset($this->rows[$table][$key]);
    }

    /** @return array<string, TableRow> */
    public function ofTable(string $table): array
    {
        return $this->rows[$table] ?? [];
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->rows));
    }

    public function clear(): void
    {
        $this->rows = [];
    }
}
