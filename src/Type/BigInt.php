<?php

declare(strict_types=1);

namespace Systopic\Db\Type;

use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\Kind;

/**
 * A 64-bit integer — `BIGINT`.
 *
 * PHP cannot express the difference: `int` is already 64-bit on every build
 * this runs on, so `public ?int $x` is *semantically* a bigint and gets mapped
 * to `int` anyway, because that is the right answer for the ninety-nine
 * columns that really are 32-bit.
 *
 * `tmp_datas.data_timestamp` is one of the exceptions — a millisecond
 * timestamp outgrows a signed 32-bit `int` in 1970 + 24 days. It needs a
 * column that says so, and since `Col` deliberately cannot change the kind,
 * saying so is a type.
 */
final class BigInt implements ColumnType
{
    public function __construct(public readonly int $value = 0)
    {
    }

    public static function column(): Column
    {
        return new Column(Kind::BigInt);
    }

    public static function fromDb(mixed $raw): static
    {
        return new static((int) $raw);
    }

    public function toDb(): mixed
    {
        return $this->value;
    }
}
