<?php

declare(strict_types=1);

namespace Systopic\Db\Result;

use PDO;
use PDOStatement;
use Systopic\Db\Schema\Registry;
use Systopic\Db\Schema\Schema;
use Systopic\Db\Schema\Table;

/**
 * The plan for a result set: which column belongs to which class?
 *
 * **Once per statement**, not per row. The first prototype computed the
 * property name per cell and was 6.6 times more expensive for it (2.79 ms
 * against 0.42 ms at 66 columns x 200 rows). That split — plan once, apply
 * often — is why ResultMap and RowReader exist separately.
 *
 * The basis is `PDOStatement::getColumnMeta()`. Measured:
 *
 *   column of a real table, no alias        -> the table name
 *   column with a table alias (p.page_id)   -> the alias
 *   column from a derived table             -> that table's alias
 *   expression, GROUP_CONCAT, subquery      -> empty
 *
 * For the first two that is enough. For the last two it is not, in principle
 * — and that is exactly why child collections do not belong in a column but in
 * a result set of their own. What cannot be mapped is not guessed here but
 * carried as a **loose value**.
 */
final class ResultMap
{
    /** @var array<int, array{class-string, string, string}> index => [class, property, column] */
    private array $mapped = [];

    /** @var array<int, string> index => column name, with no table mapped */
    private array $loose = [];

    /** @var array<class-string, Table> the tables involved */
    private array $tables = [];

    /** @var array<class-string, array<int, int>> class => key position => column index */
    private array $keyIndex = [];

    /** @var array<class-string, list<string>> class => properties delivered */
    private array $delivered = [];

    private function __construct() {}

    /**
     * @param array<string, string> $aliases alias => real table name, for
     *        queries that rename their tables. `getColumnMeta()` returns the
     *        alias there, and the registry does not know it.
     */
    public static function of(PDOStatement $st, array $aliases = []): self
    {
        $map = new self();
        $count = $st->columnCount();

        for ($i = 0; $i < $count; $i++) {
            $meta  = $st->getColumnMeta($i);
            $name  = (string) ($meta['name'] ?? '');
            $table = (string) ($meta['table'] ?? '');
            $table = $aliases[$table] ?? $table;

            $class = $table !== '' ? Registry::classFor($table) : null;
            if ($class === null) {
                $map->loose[$i] = $name;
                continue;
            }

            $schema = $map->tables[$class] ??= Schema::of($class);

            // Part of the key? Noted either way — the row reader needs every
            // position of it to build an identity, and for a junction the same
            // column is also an ordinary declared relation that has to be
            // mapped like any other.
            $keyPos = array_search($name, $schema->key, true);
            if ($keyPos !== false) {
                $map->keyIndex[$class][$keyPos] = $i;
            }

            if ($keyPos !== false && $schema->keyIsId) {
                $map->mapped[$i] = [$class, 'id', $name];
                $map->delivered[$class][] = 'id';
                continue;
            }

            $property = $schema->columnToProperty()[$name] ?? null;
            if ($property === null) {
                // The table is known but this column is not declared. Not an
                // error: the database may hold more than the class. `db audit`
                // reports such a thing, the read path ignores it.
                $map->loose[$i] = $name;
                continue;
            }

            $map->mapped[$i] = [$class, $property, $name];
            $map->delivered[$class][] = $property;
        }

        return $map;
    }

    /** @return array<int, array{class-string, string, string}> */
    public function mapped(): array
    {
        return $this->mapped;
    }

    /** @return array<int, string> */
    public function loose(): array
    {
        return $this->loose;
    }

    /** @return array<class-string, Table> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<class-string> */
    public function classes(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Did the query deliver the whole key of this class?
     *
     * Without it there is no identity: the row can go neither into the
     * identity map nor be saved later, and the row reader builds no object for
     * it at all.
     *
     * **The whole key, or nothing.** A junction that delivers `tin_tag_id` and
     * not `tin_node_id` has half an identity, which is worse than none — two
     * different rows would land on the same hash and the second would silently
     * become the first.
     *
     * @return list<int>|null the column indices, in key order
     */
    public function keyIndicesOf(string $class): ?array
    {
        $table = $this->tables[$class] ?? null;
        if ($table === null) {
            return null;
        }
        $found = $this->keyIndex[$class] ?? [];
        if (count($found) !== count($table->key)) {
            return null;
        }
        ksort($found);
        return array_values($found);
    }

    /**
     * The column index of `$id` — only for a table that has one.
     *
     * Kept apart from `keyIndicesOf()` because the callers are different: this
     * one is for the lazy ghost, whose whole identity trick is to set `$id`
     * without waking the object. A composite key has no counterpart to that,
     * so for such a table the honest answer here is "there is none".
     */
    public function primaryIndexOf(string $class): ?int
    {
        $table = $this->tables[$class] ?? null;
        return $table !== null && $table->keyIsId
            ? ($this->keyIndex[$class][0] ?? null)
            : null;
    }

    /**
     * Did the query deliver this property?
     *
     * **This question goes to the plan, never to the objects.** Measured:
     * `isInitialized()` on a lazy ghost wakes it — a completion scan over 100
     * ghosts materialised all 100. The plan knows better anyway and costs
     * nothing.
     */
    public function delivers(string $class, string $property): bool
    {
        return in_array($property, $this->delivered[$class] ?? [], true);
    }

    /** @return list<string> */
    public function deliveredProperties(string $class): array
    {
        return $this->delivered[$class] ?? [];
    }

    /**
     * A fingerprint of the result shape.
     *
     * The same `.sql` file delivers the same columns on every run, so the plan
     * can be reused across requests — and the key for that must not be the
     * query but the actual sequence of columns.
     */
    public static function fingerprint(PDOStatement $st): string
    {
        $parts = [];
        for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
            $m = $st->getColumnMeta($i);
            $parts[] = ($m['table'] ?? '') . '.' . ($m['name'] ?? '');
        }
        return md5(implode('|', $parts));
    }
}
