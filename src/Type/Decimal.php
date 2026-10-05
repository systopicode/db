<?php

declare(strict_types=1);

namespace Systopic\Db\Type;

use Stringable;
use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\Kind;
use Systopic\Db\ValueRejected;

/**
 * An exact decimal number — `DECIMAL` in both engines.
 *
 * It exists because `float` is the wrong answer to a `decimal` column, and
 * quietly so. A `float` is binary, a `decimal` is not; the value 0.1 has no
 * exact binary representation, so it goes in as something slightly else and
 * comes back as something slightly else again. For a crop coordinate that is
 * invisible, for money it is a bug people argue about for a week.
 *
 * So the value is kept as a **string**, exactly as the driver delivers it:
 *
 *     media_crop_left   decimal(13,12)   '0.500000000000'
 *
 * `(float) $crop->left` is still there for anybody who wants to compute with
 * it, and it is then deliberate rather than accidental.
 *
 * How many digits and how many decimal places the column has is not in the
 * type — the same `Decimal` is `decimal(13,12)` for a crop and `decimal(18,2)`
 * for an amount. That belongs on the property:
 *
 *     #[Col(length: 13, scale: 12)]
 *     public ?Decimal $left { set => … }
 *
 * which is the division `Col` exists for: the type decides the kind, the
 * attribute the precision.
 */
final class Decimal implements ColumnType, Stringable
{
    public function __construct(public readonly string $value = '0')
    {
    }

    /**
     * From whatever the outside hands over.
     *
     * A float is accepted but not silently rounded — it is formatted with the
     * precision PHP can still vouch for, and anything that is not a number at
     * all is rejected rather than becoming 0.
     *
     * @throws ValueRejected
     */
    public static function from(mixed $value, string $property = 'decimal'): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_int($value)) {
            return new self((string) $value);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new ValueRejected(self::class, $property, 'NAN or INF is not a number');
            }
            return new self(rtrim(rtrim(sprintf('%.14F', $value), '0'), '.') ?: '0');
        }
        if (!is_string($value) && !$value instanceof Stringable) {
            throw new ValueRejected(self::class, $property, sprintf(
                '%s is not a number', get_debug_type($value),
            ));
        }
        $text = trim((string) $value);
        if (!is_numeric($text)) {
            throw new ValueRejected(self::class, $property, sprintf(
                '"%s" is not a number', $text,
            ));
        }
        return new self($text);
    }

    /**
     * Without precision — that comes from `#[Col]`, and without it the
     * platform falls back to its own default (`decimal(10,2)`).
     */
    public static function column(): Column
    {
        return new Column(Kind::Decimal);
    }

    public static function fromDb(mixed $raw): static
    {
        return new static((string) ($raw ?? '0'));
    }

    public function toDb(): mixed
    {
        return $this->value;
    }

    public function toFloat(): float
    {
        return (float) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
