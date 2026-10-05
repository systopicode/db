<?php

declare(strict_types=1);

namespace Systopic\Db;

use ReflectionProperty;
use Systopic\Db\Schema\Schema;
use Systopic\Db\Schema\Table;

/**
 * What makes one row one row — as a string the identity map can key on.
 *
 * Used to be a number, and for twenty of the twenty-seven tables of this
 * installation it still is: `Identity::hash([17])` is `'17'`, so a key stays
 * readable in a dump and a var_dump of the map still tells you what is in it.
 *
 * The eight junctions are keyed on two columns, so the key had to become a
 * tuple somewhere. Doctrine solves it the same way — its identity map holds
 * "a sorted, serialized version of all the key columns" — and the separator is
 * the only real decision: `\x1f` (ASCII unit separator) rather than a comma or
 * a space, because it is the one character that cannot turn up inside an id or
 * inside a string key and make two different rows collide on one hash.
 *
 * There are two ways to ask, and the difference matters:
 *
 *     hash($values)     from the raw row, while reading  — no object needed
 *     of($row)          from the object, before writing  — reads the properties
 *
 * The reading side never goes through the object, and that is deliberate: at
 * the point the row reader needs the identity, the object may not exist yet.
 */
final class Identity
{
    /**
     * Separator between the parts of a composite key.
     *
     * ASCII 31, "unit separator". Not a comma: `'1,2'` and `'1'` with a comma
     * in it would be the same string, and two different rows would then share
     * an identity — which is the one thing an identity map must never do.
     */
    public const SEPARATOR = "\x1f";

    /** @param list<mixed> $values the key values, in key order */
    public static function hash(array $values): string
    {
        return count($values) === 1
            ? (string) $values[0]
            : implode(self::SEPARATOR, array_map(static fn($v): string => (string) $v, $values));
    }

    /**
     * The identity of a row, or NULL when it does not have one yet.
     *
     * NULL means what it says: a row that was never saved and whose key is an
     * AUTO_INCREMENT nobody has filled in, or a junction whose sides have not
     * both been set. Either way there is nothing to look it up by and nothing
     * to write it back to.
     */
    public static function of(TableRow $row, ?Table $table = null): ?string
    {
        $values = self::valuesOf($row, $table);
        return $values === null ? null : self::hash($values);
    }

    /**
     * The key values of a row, in key order — NULL if any of them is missing.
     *
     * @return list<mixed>|null
     */
    public static function valuesOf(TableRow $row, ?Table $table = null): ?array
    {
        $table ??= Schema::of($row::class);

        $values = [];
        foreach ($table->keyProperties as $property) {
            $value = self::valueOf($row, $table, $property);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }
        return $values;
    }

    /**
     * One key value — and the reason this is not simply `$row->$property`.
     *
     * A key property of a junction is a **relation**: `TagInNode::$tag` is a
     * `Tag`, the column holds a number. Which of the two is in reach depends
     * on what the query delivered — the row reader wires the object when the
     * same result carried it and otherwise leaves the id waiting in `refIds`.
     * Both answers are the same identity, so both are accepted here.
     *
     * The order is not arbitrary either: the **object first**, because a row
     * that was just built by hand (`$link->tag = $tag`) has the object and no
     * `refIds` at all.
     */
    private static function valueOf(TableRow $row, Table $table, string $property): mixed
    {
        // `$id` is released on a lazy ghost, so reading it never wakes one.
        if ($property === 'id') {
            return $row->id;
        }

        $handle = new ReflectionProperty($row::class, $property);
        if ($handle->isInitialized($row)) {
            $value = $handle->getRawValue($row);
            if ($value !== null) {
                return $value instanceof TableRow ? $value->id : Extractor::toDb($value);
            }
        }

        $column = $table->propertyToColumn[$property] ?? null;
        return $column !== null && isset($table->foreignKeys[$column])
            ? $row->refId($property)
            : null;
    }
}
