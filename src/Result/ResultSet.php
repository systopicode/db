<?php

declare(strict_types=1);

namespace Systopic\Db\Result;

use Systopic\Db\TableRow;

/**
 * One result set.
 *
 * A `.sql` file may contain several — `TagGroups.sql` returns three
 * (groups, tags, assignments) in one roundtrip. Each gets a plan of its own,
 * because each has different columns.
 *
 * The reason for this shape is in section 14 of the design: only several
 * result sets and separate SELECTs deliver **real table names**. Packing a
 * child collection into a column delivers `table = ""`, and the row reader can
 * do nothing with that.
 */
final class ResultSet
{
    /**
     * @param list<array<class-string, TableRow|null>> $records
     * @param list<array<string, mixed>>               $loose
     */
    public function __construct(
        public readonly ResultMap $map,
        public readonly array $records,
        public readonly array $loose,
    ) {}

    /** @return list<TableRow> every object of a class, without repetitions */
    public function all(string $class): array
    {
        $out = [];
        foreach ($this->records as $record) {
            $object = $record[$class] ?? null;
            if ($object !== null && !in_array($object, $out, true)) {
                $out[] = $object;
            }
        }
        return $out;
    }

    public function first(string $class): ?TableRow
    {
        foreach ($this->records as $record) {
            if (($record[$class] ?? null) !== null) {
                return $record[$class];
            }
        }
        return null;
    }

    public function count(): int
    {
        return count($this->records);
    }

    /** Loose values only, no mapped table — a junction without an id, say. */
    public function isLooseOnly(): bool
    {
        return $this->map->mapped() === [];
    }
}
