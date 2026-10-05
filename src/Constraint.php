<?php

declare(strict_types=1);

namespace Systopic\Db;

use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\Kind;
use Systopic\Db\Schema\Schema;
use Systopic\Db\Type\ColumnType;

/**
 * What a declared column will accept — checked at the assignment.
 *
 * The rule the whole thing rests on: **give the driver correct data.** PHP
 * already guarantees half of it — a `public ?int $ordering` cannot hold a
 * string, a `public ?DateTimeImmutable $created` cannot hold an invalid date.
 * The other half is what the type system cannot say: how long a string may be,
 * how large a number may get, whether NULL is allowed.
 *
 * That half lives in the declaration — `varchar(255)` comes out of
 * `Column::length`, the range out of `Kind` and `unsigned` — and this class is
 * where it gets asked.
 *
 * **Why at the assignment and not at the UPDATE.** In this installation the
 * connection runs without `STRICT_TRANS_TABLES` (see `db.php:103`), so MySQL
 * truncates a value that is too long **silently**: no error, no warning, a
 * shortened row. A check here turns that into an exception naming the
 * property, before anything reaches the driver.
 *
 * And it does not collide with the provisioner. The two answer different
 * questions:
 *
 *     does the value fit the **column**?       the driver, 1406 / 1264 ->
 *                                              the provisioner widens
 *     does the value fit the **declaration**?  this, before the write ->
 *                                              ValueRejected, travels up
 *
 * A column that has fallen behind the declaration gets widened; a value the
 * declaration never promised to hold is rejected. Widening it would quietly
 * change what the model says.
 *
 * **Cost.** `__set__()` runs on every assignment, so the check must not reflect
 * per call. The derived constraint is cached per class and property; what is
 * left per assignment is an array lookup and, for a string, an `mb_strlen`.
 */
final class Constraint
{
    /** @var array<string, self|null> class::property => constraint, null = nothing to check */
    private static array $cache = [];

    private function __construct(
        private readonly Kind $kind,
        private readonly ?int $length,
        private readonly bool $nullable,
        private readonly bool $unsigned,
        /** @var list<string> */
        private readonly array $enumCases,
        /** @var class-string|null the type class, when the property is one */
        private readonly ?string $typeClass,
    ) {}

    /**
     * Check a value against the declaration of one property.
     *
     * @return mixed the value, unchanged — the check throws or it does not
     * @throws ValueRejected
     */
    public static function check(string $class, string $property, mixed $value): mixed
    {
        $constraint = self::$cache[$class . '::' . $property] ??= self::derive($class, $property);
        return $constraint?->apply($class, $property, $value) ?? $value;
    }

    /** @throws ValueRejected */
    private function apply(string $class, string $property, mixed $value): mixed
    {
        if ($value === null) {
            if (!$this->nullable) {
                throw new ValueRejected($class, $property, 'NULL, but the column is NOT NULL');
            }
            return null;
        }

        // A type class has already been validated **as a type** — by PHP. The
        // property is declared as that class, so nothing else ever got this
        // far, and the class made sure of its content at construction:
        // `Date::from()` is where an impossible date is rejected, not here.
        //
        // That order matters and it is not obvious: **PHP's declared type is
        // checked before the hook runs.** A type class therefore cannot
        // validate its own raw input from inside `__set__()` — the TypeError
        // comes first. Which is why validation belongs in the type's factory.
        //
        // What the type class does **not** guarantee is that its content fits
        // the column. A `Text` is a `Text` at any length; whether it goes into
        // a `text(65535)` is a different question. So the column checks below
        // run against what would actually be written — `toDb()` — and the
        // original value comes back out.
        $written = $value instanceof ColumnType ? $value->toDb() : $value;

        match ($this->kind) {
            Kind::String   => $this->checkString($class, $property, $written),
            Kind::Text     => $this->checkText($class, $property, $written),
            Kind::Int,
            Kind::BigInt   => $this->checkInt($class, $property, $written),
            default        => null,
        };

        return $value;
    }

    private function checkString(string $class, string $property, mixed $value): void
    {
        if ($this->enumCases !== [] && !in_array((string) $value, $this->enumCases, true)) {
            throw new ValueRejected($class, $property, sprintf(
                '"%s" is not one of %s',
                (string) $value,
                implode(', ', $this->enumCases),
            ));
        }
        $limit = $this->length ?? 255;
        $length = mb_strlen((string) $value);
        if ($length > $limit) {
            throw new ValueRejected($class, $property, sprintf(
                '%d characters, the column holds %d', $length, $limit,
            ));
        }

    }

    /**
     * TEXT is measured in **bytes**, not characters — and that is where the
     * silent truncation of a multi-byte string comes from: an umlaut is two
     * bytes in UTF-8, so 32.768 of them are 65.536 and one too many for a
     * `text` column, while `mb_strlen` still reads a comfortable 32.768.
     */
    private function checkText(string $class, string $property, mixed $value): void
    {
        $limit = $this->length;
        if ($limit === null) {
            return;   // longtext — nothing worth checking
        }
        $bytes = strlen((string) $value);
        if ($bytes > $limit) {
            throw new ValueRejected($class, $property, sprintf(
                '%d bytes, the column holds %d', $bytes, $limit,
            ));
        }

    }

    private function checkInt(string $class, string $property, mixed $value): void
    {
        $number = (int) $value;
        [$min, $max] = match (true) {
            $this->kind === Kind::BigInt && $this->unsigned => [0, PHP_INT_MAX],
            $this->kind === Kind::BigInt                    => [PHP_INT_MIN, PHP_INT_MAX],
            $this->unsigned                                 => [0, 4294967295],
            default                                         => [-2147483648, 2147483647],
        };
        if ($number < $min || $number > $max) {
            throw new ValueRejected($class, $property, sprintf(
                '%d, the column holds %d to %d', $number, $min, $max,
            ));
        }

    }

    // ----------------------------------------------------------- derivation

    /** Once per class and property — everything here is reflection. */
    private static function derive(string $class, string $property): ?self
    {
        $table = Schema::of($class);
        $column = $table->column($property);
        if ($column === null) {
            return null;   // not a column — nothing declared, nothing to check
        }

        // A relation carries an object, not a value: `Page::$node` is checked
        // by PHP's own type, and its id only comes into being on write.
        if (isset($table->foreignKeys[$table->propertyToColumn[$property] ?? ''])) {
            return null;
        }

        return new self(
            kind: $column->kind,
            length: $column->length,
            nullable: $column->nullable,
            unsigned: $column->unsigned,
            enumCases: $column->enumCases,
            typeClass: self::typeClassOf($class, $property),
        );
    }

    /**
     * The type class behind a property, when there is one.
     *
     * Only used to step aside: a value that already is a `Date` cannot be
     * wrong in a way a column description would notice.
     *
     * @return class-string<ColumnType>|null
     */
    private static function typeClassOf(string $class, string $property): ?string
    {
        $type = (new \ReflectionProperty($class, $property))->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $name = $type->getName();
        return is_a($name, ColumnType::class, true) ? $name : null;
    }

    /** For tests: forget the derived constraints. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /** @internal for the check step — what was derived for a property. */
    public static function describe(string $class, string $property): ?array
    {
        $constraint = self::$cache[$class . '::' . $property] ??= self::derive($class, $property);
        return $constraint === null ? null : [
            'kind'      => $constraint->kind->name,
            'length'    => $constraint->length,
            'nullable'  => $constraint->nullable,
            'unsigned'  => $constraint->unsigned,
            'typeClass' => $constraint->typeClass,
        ];
    }
}
