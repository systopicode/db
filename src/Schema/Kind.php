<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/**
 * What a column carries — engine-neutral.
 *
 * Deliberately coarse: this is the common ground MySQL and Postgres both
 * know. How `INT UNSIGNED` or `integer` comes out of it is decided by the
 * platform, not by this enum. That is exactly why no SQL may live here.
 */
enum Kind
{
    case Int;
    case BigInt;
    case Float;
    case Decimal;
    case Bool;
    case String;
    case Text;
    case Json;
    case Date;
    case DateTime;
    case Time;
    case Binary;
}
