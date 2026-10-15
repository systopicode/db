<?php

declare(strict_types=1);

namespace Systopic\Db;

/**
 * Someone who wants to know what a request reads and writes — without the
 * ORM knowing who: systopic/relay maps both onto its channels, a layer cache
 * could do the same.
 *
 * Registered once at boot with `Scope::addObserver()` (from a package's
 * Composer `autoload.files`). Without one, the scope does nothing extra.
 *
 * An observer must not throw — the scope catches and reports it as a warning,
 * because a commit it is told about is already in the database.
 */
interface Observer
{
    /**
     * A query result was handed out: run now, served from the result cache,
     * or as part of an object `Scope::objectFor()` built earlier — every time,
     * so whoever is rendering at that moment learns what it depends on.
     *
     * The tables a result read are in `$rows->sets()` (`ResultMap::tables()`),
     * its rows in `$rows->records()`.
     */
    public function read(string $key, Rows $rows): void;

    /**
     * After the transaction held. Written rows still carry their changes
     * (`TableRow::changes()`).
     *
     * @param list<TableRow> $written  rows inserted, or updated with a value that
     *                                 differs (a value saved again unchanged is not here)
     * @param list<TableRow> $removed  rows deleted
     */
    public function committed(array $written, array $removed): void;
}
