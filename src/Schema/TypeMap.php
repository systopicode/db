<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use ReflectionEnum;
use ReflectionNamedType;
use RuntimeException;
use Systopic\Db\TableRow;
use Systopic\Db\Type\ColumnType;

/**
 * PHP type -> column.
 *
 * The one place where it is decided what a type means in the database.
 * Everything else in the schema package asks here.
 *
 * If a type does not suffice — `array`, `mixed`, a foreign class — that gets
 * **loud**, not silent: the design demands a type class of its own, and an
 * exception while building the schema is the moment it shows.
 */
final class TypeMap
{
    /**
     * @return array{0: Column, 1: ?string}  the column and, for a relation,
     *                                       the target class
     */
    public static function map(ReflectionNamedType $type, string $property): array
    {
        $name = $type->getName();

        if ($type->isBuiltin()) {
            // `string` carries its length here and not only in the platform.
            // `MySql::renderColumn()` has a `?? 255` of its own, and as long
            // as the column left it open, two places knew the default and
            // `Constraint` had to guess a third time. Now the declaration
            // says it once.
            return [match ($name) {
                'int'    => new Column(Kind::Int),
                'float'  => new Column(Kind::Float),
                'bool'   => new Column(Kind::Bool),
                'string' => new Column(Kind::String, length: 255),
                default  => throw new RuntimeException(
                    "no database type for '$name' (\$$property) — that needs a type class of "
                    . 'its own with ' . ColumnType::class
                ),
            }, null];
        }

        // Time: DateTimeImmutable is the normal case. DateTime is mutable and
        // therefore a trap as a column value — changing it changes nothing
        // anybody gets to hear about.
        if ($name === \DateTimeImmutable::class) {
            return [new Column(Kind::DateTime), null];
        }
        if ($name === \DateTime::class) {
            throw new RuntimeException(
                "\$$property is DateTime — please use DateTimeImmutable: a mutable value "
                . 'bypasses the change tracking in the set hook'
            );
        }

        if (enum_exists($name)) {
            $enum = new ReflectionEnum($name);
            if (!$enum->isBacked()) {
                throw new RuntimeException("enum \$$property must be backed (enum X: string)");
            }
            $cases = array_column($name::cases(), 'value');
            return (string) $enum->getBackingType() === 'int'
                ? [new Column(Kind::Int), null]
                : [new Column(Kind::String, enumCases: $cases), null];
        }

        if (is_a($name, ColumnType::class, true)) {
            return [$name::column(), null];
        }

        // The type is a row itself -> foreign key.
        if (is_a($name, TableRow::class, true)) {
            return [new Column(Kind::Int, index: Index::Plain, unsigned: true), $name];
        }

        throw new RuntimeException(
            "unknown type '$name' for \$$property — either a TableRow class or a "
            . 'type class with ' . ColumnType::class
        );
    }
}
