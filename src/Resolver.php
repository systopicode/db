<?php

declare(strict_types=1);

namespace Systopic\Db;

use ReflectionClass;
use ReflectionProperty;
use Systopic\Db\Schema\Schema;

/**
 * Turns the id in a foreign key into the object behind it.
 *
 * `RowReader::wire()` already links what the query delivered: if `pages.sql`
 * joined `usm_users`, then `$page->createdUser` is the very `User` from that
 * result. This is the other half — what happens when the target table was
 * **not** in the query. The id sits in `refIds` and waits, and this is what it
 * waits for.
 *
 * Two rules, and the order matters:
 *
 * **The identity map first.** If that user is already in memory, the same
 * object comes back. Two references to row 3 must be one object, otherwise a
 * change made through one of them is invisible through the other — and
 * `===` stops meaning anything.
 *
 * **Otherwise a lazy ghost**, with its id set and released. `$user->id` costs
 * nothing; `$user->name` is what fetches the row. That is what makes
 * `$page->createdUser` affordable in a loop over a tree: the object exists,
 * is of the right type, and has not touched the database.
 *
 * It lives beside the row rather than inside it. A `TableRow` knows nothing
 * below itself — no connection, no reader — and that stays true: it is
 * *handed* a resolver, it never goes looking for one.
 */
final class Resolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identity,
    ) {}

    /**
     * The object a relation points at, or null when it points at nothing.
     *
     * @param class-string $class     the class holding the relation
     * @param string       $property  the relation property on it
     */
    public function relation(string $class, string $property, int $id): ?TableRow
    {
        $table  = Schema::of($class);
        $column = $table->propertyToColumn[$property] ?? null;
        $fk     = $column === null ? null : ($table->foreignKeys[$column] ?? null);
        if ($fk === null) {
            return null;   // not a relation — nothing to resolve
        }

        // A foreign key points at a single key by definition, so the identity
        // is the id — `Identity::hash([$id])` would return the same string.
        $key = (string) $id;

        $known = $this->identity->get($fk->table, $key);
        if ($known !== null) {
            return $known;
        }

        return $this->identity->put($fk->table, $key, $this->ghost($fk->class, $id));
    }

    /**
     * The rest of a partial row — every column the query left out, in one
     * statement, on the first read of one of them.
     *
     * The mirror image of `ghost()`: there the whole object waits for its
     * first access, here only the columns a lean query did not deliver. What
     * makes it necessary is the shared identity map — the first, leaner query
     * wins the object, and a template reading `$page->html01` afterwards
     * must not fail with "must not be accessed before initialization".
     *
     * `Scope::need()` does the same for a whole load group at once; this is
     * the fallback for a row read outside of that, and it costs one statement
     * per row.
     *
     * @return bool whether the row was found
     */
    public function complete(TableRow $row): bool
    {
        $class = $row::class;
        $table = Schema::of($class);
        if (!$table->keyIsId || $row->id === null) {
            return false;
        }
        $data = $this->connection->one(
            'SELECT * FROM ' . $this->connection->platform()->quote($table->name)
            . ' WHERE ' . $this->connection->platform()->quote($table->primary) . ' = ?',
            [$row->id],
        );
        if ($data === null) {
            return false;
        }
        $refs = [];
        foreach ($table->propertyToColumn as $property => $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $rp = new ReflectionProperty($class, $property);
            if ($row->knows($property)) {
                continue;   // the query delivered it, or somebody assigned it
            }
            if (isset($table->foreignKeys[$column])) {
                if ($row->refId($property) !== null) {
                    continue;   // known already, only never resolved
                }
                if ($data[$column] !== null && (int) $data[$column] > 0) {
                    $refs[$property] = (int) $data[$column];
                } else {
                    $rp->setRawValue($row, null);   // points nowhere — a fact
                }
                continue;
            }
            $rp->setRawValue($row, Extractor::fromDb($class, $property, $data[$column]));
        }
        if ($refs !== []) {
            $row->addRefIds($refs);
        }
        $row->markComplete();
        return true;
    }

    /**
     * A real object of its class whose row arrives on first access.
     *
     * The id is written **without** waking it and then released, the same way
     * `RowReader::buildGhost()` does it: reading an identity must not cost a
     * query, or every `in_array($user, $users, true)` would materialise the
     * lot.
     *
     * @param class-string $class
     */
    private function ghost(string $class, int $id): TableRow
    {
        $rc         = new ReflectionClass($class);
        $connection = $this->connection;
        $resolver   = $this;

        /** @var TableRow $ghost */
        $ghost = $rc->newLazyGhost(static function (TableRow $object) use ($class, $id, $connection, $resolver): void {
            $table = Schema::of($class);
            $row = $connection->one(
                'SELECT * FROM ' . $connection->platform()->quote($table->name)
                . ' WHERE ' . $connection->platform()->quote($table->primary) . ' = ?',
                [$id],
            );

            // The row may be gone. The object stays what it is — an object
            // with an id — because the alternative is a TypeError in a
            // template that only wanted to print a name.
            if ($row === null) {
                $object->markLoaded(readOnly: true);
                return;
            }

            $refs = [];
            foreach ($table->propertyToColumn as $property => $column) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                if (isset($table->foreignKeys[$column])) {
                    if ($row[$column] !== null && (int) $row[$column] > 0) {
                        $refs[$property] = (int) $row[$column];
                    } else {
                        (new ReflectionProperty($class, $property))->setRawValue($object, null);
                    }
                    continue;   // a relation is not a number
                }
                (new ReflectionProperty($class, $property))
                    ->setRawValue($object, Extractor::fromDb($class, $property, $row[$column]));
            }
            // Its own relations resolve the same way — otherwise the chain
            // would stop after one step: $page->createdUser works, and
            // $page->node->parentNode does not.
            $object->setRefIds($refs);
            $object->setResolver($resolver);
            $object->markLoaded();
        });

        $rp = new ReflectionProperty($class, 'id');
        $rp->setRawValueWithoutLazyInitialization($ghost, $id);
        $rp->skipLazyInitialization($ghost);

        return $ghost;
    }
}
