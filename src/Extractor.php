<?php

declare(strict_types=1);

namespace Systopic\Db;

use BackedEnum;
use DateTimeImmutable;
use ReflectionNamedType;
use ReflectionProperty;
use Systopic\Db\Type\ColumnType;

/**
 * Raw value <-> PHP value.
 *
 * The role that was missing from the first prototype. `setRawValue()` checks
 * the type of a property but only converts what PHP can convert itself: `'42'`
 * becomes `int 42`, `'hello'` throws. What it **cannot** do is a `DATETIME`
 * from the database — that arrives as a string and would throw a TypeError
 * against `public DateTimeImmutable $created` immediately.
 *
 * That is what this class is for, and it runs **before** writing into the
 * property, not after.
 *
 * Measured (orm-types-probe.php, PHP 8.5.7 / MySQL 8.4.3):
 *
 *   INT        -> int      natively, in both prepare modes
 *   DOUBLE     -> float    natively
 *   DECIMAL    -> string   always — precision, hence a type class of its own
 *   DATETIME   -> string   always
 *   TINYINT(1) -> int      not bool
 */
final class Extractor
{
    /** @var array<string, ReflectionNamedType|false> class::property => type */
    private static array $types = [];

    /**
     * From the database into the PHP value.
     *
     * @param class-string $class
     */
    public static function fromDb(string $class, string $property, mixed $raw): mixed
    {
        $type = self::typeOf($class, $property);
        if ($type === null) {
            return $raw;
        }
        if ($raw === null) {
            return null;   // NULL stays NULL; whether that is allowed is the type's call
        }

        $name = $type->getName();

        if ($type->isBuiltin()) {
            // The weak conversion of setRawValue() is enough for int, float and
            // string. Only bool needs help: TINYINT(1) arrives as an int, and
            // '0' as a string would otherwise be true.
            return $name === 'bool' ? (bool) (int) $raw : $raw;
        }

        if ($name === DateTimeImmutable::class) {
            // '0000-00-00 00:00:00' is not a date but a legacy artefact.
            if (!is_string($raw) || $raw === '' || str_starts_with($raw, '0000-00-00')) {
                return null;
            }
            return new DateTimeImmutable($raw);
        }

        if (is_a($name, ColumnType::class, true)) {
            return $name::fromDb($raw);
        }

        if (enum_exists($name)) {
            /** @var class-string<BackedEnum> $name */
            return $name::tryFrom(is_int($raw) ? $raw : (string) $raw);
        }

        // A relation: the column holds the id, not the object. Turning that
        // into an object is the row reader's job — it knows the identity map.
        // Here the id passes through.
        if (is_a($name, TableRow::class, true)) {
            return $raw === null ? null : (int) $raw;
        }

        return $raw;
    }

    /** From the PHP value back into the database. */
    public static function toDb(mixed $value): mixed
    {
        return match (true) {
            $value === null            => null,
            $value instanceof ColumnType       => $value->toDb(),
            $value instanceof BackedEnum       => $value->value,
            $value instanceof DateTimeImmutable => $value->format('Y-m-d H:i:s'),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof TableRow => self::idOf($value),
            is_bool($value)            => $value ? 1 : 0,
            default                    => $value,
        };
    }

    /**
     * The column holds the id, not the object.
     *
     * `isInitialized()` instead of a direct access: on a lazy ghost `$row->id`
     * would materialise the object — and that is precisely not the point here.
     */
    private static function idOf(TableRow $row): ?int
    {
        $rp = new ReflectionProperty($row::class, 'id');
        return $rp->isInitialized($row) ? $rp->getValue($row) : null;
    }

    private static function typeOf(string $class, string $property): ?ReflectionNamedType
    {
        $key = $class . '::' . $property;
        if (!array_key_exists($key, self::$types)) {
            try {
                $t = (new ReflectionProperty($class, $property))->getType();
                self::$types[$key] = $t instanceof ReflectionNamedType ? $t : false;
            } catch (\ReflectionException) {
                self::$types[$key] = false;
            }
        }
        return self::$types[$key] ?: null;
    }
}
