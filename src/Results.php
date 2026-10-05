<?php

declare(strict_types=1);

namespace Systopic\Db;

/**
 * Query results of one request, and which tables each of them read.
 *
 * The rule of the old MySQL query cache, one level up: a result is kept
 * under (query, parameters) and thrown away the moment any table it read is
 * written to. Coarse, always right, and it needs no listener per view and no
 * hand-maintained cascade — that was the finding of the 28.09. round: a
 * collection whose membership depends on `:lang`, `:preview`, `NOW()` and
 * `:showtrashed` has no rule that could be followed in PHP, so it is not
 * followed but invalidated.
 *
 * Which tables a result read comes from the result itself: `ResultMap` knows
 * them from PDO's column metadata, so nobody has to declare them.
 */
final class Results
{
    /** @var array<string, Rows> key => result */
    private array $rows = [];

    /** @var array<string, list<string>> key => table names read */
    private array $tables = [];

    private int $hits = 0;

    public function get(string $key): ?Rows
    {
        $hit = $this->rows[$key] ?? null;
        if ($hit !== null) {
            $this->hits++;
        }
        return $hit;
    }

    /** Remember a result together with the tables it read. */
    public function put(string $key, Rows $rows): Rows
    {
        $tables = [];
        foreach ($rows->sets() as $set) {
            foreach ($set->map->tables() as $table) {
                $tables[$table->name] = true;
            }
        }
        $this->rows[$key] = $rows;
        $this->tables[$key] = array_keys($tables);
        return $rows;
    }

    /**
     * Something was written to these tables: every result that read one of
     * them is stale now.
     *
     * @param list<string> $tableNames
     */
    public function invalidate(array $tableNames): void
    {
        if ($tableNames === []) {
            return;
        }
        foreach ($this->tables as $key => $read) {
            if (array_intersect($read, $tableNames) !== []) {
                unset($this->rows[$key], $this->tables[$key]);
            }
        }
    }

    public function clear(): void
    {
        $this->rows = [];
        $this->tables = [];
    }

    /** How often a result came from here instead of the database. */
    public function hits(): int
    {
        return $this->hits;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** The key of a query: its file (or text) and its parameters. */
    public static function key(string $source, array $params, string $variant = ''): string
    {
        ksort($params);
        return $source . '|' . $variant . '|' . json_encode($params, JSON_UNESCAPED_SLASHES);
    }
}
