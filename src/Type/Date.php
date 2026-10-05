<?php

declare(strict_types=1);

namespace Systopic\Db\Type;

use DateTimeImmutable;
use DateTimeInterface;
use Stringable;
use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\Kind;
use Systopic\Db\ValueRejected;

/**
 * A day without a time — `DATE` in MySQL, `date` in Postgres.
 *
 * It exists for the thing `DateTimeImmutable` does silently: **roll an
 * impossible date over.** `new DateTimeImmutable('2024-02-30')` is not an
 * error, it is the 1st of March. For a value coming out of a form or an import
 * that is a correction nobody asked for.
 *
 *     Date::from('2024-02-29')   // fine, 2024 is a leap year
 *     Date::from('2024-02-30')   // ValueRejected
 *
 * **The check sits in `from()` and not in the set hook, and that is not a
 * detail.** A property declared `public ?Date $publishedOn` is guarded by PHP
 * itself: a string never reaches `__set__()`, the TypeError comes first. So a
 * type class cannot validate its own input from inside the hook — by the time
 * the hook runs, the instance exists and is valid by construction.
 *
 * Which is why the work is split the way it is:
 *
 *     PHP's declared type   is it the right kind of thing?   before the hook
 *     Date::from()          is this string a date?           at construction
 *     Constraint            does it fit the declared column? inside __set__()
 *
 * The edge of the application calls `from()`; everything behind it holds a
 * `Date` and cannot hold anything else.
 */
final class Date implements ColumnType, Stringable
{
    public function __construct(public readonly DateTimeImmutable $value)
    {
    }

    /**
     * From whatever the outside hands over — and strictly.
     *
     * `createFromFormat` plus `getLastErrors()` is the only way PHP tells
     * "2024-02-30" apart from a date; the constructor would take it and move
     * on to March.
     *
     * @throws ValueRejected
     */
    public static function from(mixed $value, string $property = 'date'): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return new self(DateTimeImmutable::createFromInterface($value));
        }
        if (!is_string($value) && !$value instanceof Stringable) {
            throw new ValueRejected(self::class, $property, sprintf(
                '%s is not a date', get_debug_type($value),
            ));
        }

        $text = trim((string) $value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            throw new ValueRejected(self::class, $property, sprintf(
                '"%s" is not a date in Y-m-d', $text,
            ));
        }
        return new self($parsed);
    }

    public static function column(): Column
    {
        return new Column(Kind::Date);
    }

    /**
     * Out of the database it comes without a check: what is in a `DATE` column
     * is a date, and `0000-00-00` is caught by `Extractor` before it gets
     * here.
     */
    public static function fromDb(mixed $raw): static
    {
        return new static(new DateTimeImmutable((string) $raw));
    }

    public function toDb(): mixed
    {
        return $this->value->format('Y-m-d');
    }

    public function __toString(): string
    {
        return $this->value->format('Y-m-d');
    }
}
