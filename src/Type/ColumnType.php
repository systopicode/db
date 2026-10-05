<?php

declare(strict_types=1);

namespace Systopic\Db\Type;

use Systopic\Db\Schema\Column;

/**
 * A type class of its own for everything a PHP scalar does not cover.
 *
 * The design demands: the type of the property determines the data type of the
 * column. Where PHP has no fitting type — slug, money, colour, JSON — a class
 * comes into being that answers three things:
 *
 *   column()  what the column looks like  (engine-neutral, never SQL)
 *   fromDb()  how the value comes in
 *   toDb()    how it goes back out
 *
 * `column()` is static on purpose: the schema has to know the column without
 * ever having seen an instance.
 */
interface ColumnType
{
    /** What the column looks like — without nullability, which comes from the property. */
    public static function column(): Column;

    /** From the raw database value. */
    public static function fromDb(mixed $raw): static;

    /** Back into the database. */
    public function toDb(): mixed;
}
