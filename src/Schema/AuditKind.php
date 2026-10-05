<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/**
 * What kind of action the audit proposes.
 *
 * **The order is the order of execution**, and `Audit::run()` sorts by it so
 * that what a caller shows and what it runs are the same sequence.
 *
 * Mostly that is the order of danger — what creates comes before what drops.
 * `ClearOrphans` is the exception and shows why the two are not the same
 * thing: it throws something away, and it still has to run early, because the
 * constraint behind it cannot be added while the rows it clears are still
 * there. Danger is expressed by the marking and by not pre-selecting the
 * action, never by its position in this list.
 */
enum AuditKind: string
{
    case CreateTable   = 'create table';
    case AddColumn     = 'add column';
    case ClearOrphans  = 'clear orphans';
    case AddConstraint = 'add constraint';
    case CreateIndex   = 'create index';
    case Migration     = 'migration';
    // Dropping an index throws no data away — an index is derived from the
    // table and can be rebuilt from it. It sits here rather than with the
    // harmless ones because it is still worth looking at: what disappears is
    // the reason some query was fast.
    case DropIndex     = 'drop index';
    case ModifyColumn  = 'modify column';
    case DropColumn    = 'drop column';
    case DropTable     = 'drop table';
}
