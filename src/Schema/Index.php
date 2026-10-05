<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/** What kind of index sits on a column. */
enum Index
{
    case Plain;
    case Unique;
    case Fulltext;
}
