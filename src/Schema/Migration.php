<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use Systopic\Db\Connection;

/**
 * A non-trivial change that has to come along when something is created.
 *
 * The provisioner adds columns and creates tables; it never moves data. Taking
 * something over, renaming it, converting it — that is a migration, and it
 * lives in a directory of its own. The provisioner only calls it.
 *
 *     final class PageSubtitle extends Migration
 *     {
 *         public const ON_CREATE = 'cms_pages.page_subtitle';
 *
 *         public function run(Connection $c): void
 *         {
 *             $c->exec("UPDATE cms_pages SET page_subtitle = page_teaser");
 *             $c->exec("ALTER TABLE cms_pages DROP COLUMN page_teaser");
 *         }
 *     }
 *
 * Three hooks, and the name says when they fire:
 *
 *   ON_CREATE  'cms_pages.page_subtitle'   after ADD COLUMN
 *   ON_CREATE  'cms_tags'                  after CREATE TABLE
 *   ON_WIDEN   'cms_pages.page_label'      after MODIFY, for the 1406 case
 *
 * **`DROP COLUMN` needs no migration.** Take the property out of the class,
 * run `db audit`, execute the DROP it prints. A migration is only there for
 * what has to come along while something is being *created*.
 *
 * `run()` has to be repeatable. The reason is the one gap in the bookkeeping:
 * if the process dies between `ADD COLUMN` and the `INSERT` into
 * `sys_migrations`, the column exists, 1054 never fires again, and the hook
 * would never run. The entry is therefore written *after* `run()`, and on a
 * restart the hook runs once more. For "copy A to B, drop A" that holds
 * anyway.
 */
abstract class Migration
{
    /** What this migration hangs on: 'table' or 'table.column'. */
    public const ON_CREATE = '';

    /** The same, for the widening case. */
    public const ON_WIDEN = '';

    abstract public function run(Connection $connection): void;

    /** The name under which it is recorded. */
    public static function name(): string
    {
        return static::class;
    }
}
