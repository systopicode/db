<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/** How one column stands between the database and the declaration. */
enum StatusState: string
{
    /** Both sides say the same thing. */
    case Same = 'same';

    /** Both have the column, the definitions disagree. */
    case Different = 'different';

    /** Declared, but not in the database — it would be created. */
    case OnlyDeclared = 'only declared';

    /** In the database, but in no declaration — orphaned. */
    case OnlyInDatabase = 'only in database';

    /**
     * There is no declaration for this table at all.
     *
     * Not the same as orphaned, and the difference matters: a junction table
     * has no class **by design**, so its columns are not abandoned, they are
     * out of scope. Colouring them like a finding would train people to
     * ignore the colour.
     */
    case Unknown = 'no model';
}
