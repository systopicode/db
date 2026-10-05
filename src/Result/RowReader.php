<?php

declare(strict_types=1);

namespace Systopic\Db\Result;

use ReflectionClass;
use ReflectionProperty;
use Systopic\Db\Extractor;
use Systopic\Db\Identity;
use Systopic\Db\IdentityMap;
use Systopic\Db\Resolver;
use Systopic\Db\Schema\Schema;
use Systopic\Db\TableRow;

/**
 * Turning a row into objects — per row, following the plan of the ResultMap.
 *
 * Three things matter here, and all three are measured:
 *
 * **1. The plan is not recomputed.** The result map says for every column
 * index where the value belongs. Computing the property name per cell cost
 * 6.6 times as much in the first prototype.
 *
 * **2. Side tables come into being lazily.** `pages.sql` delivers three tables
 * per row; anybody who only reads `$page->label` needs neither Node nor
 * Rewritepath. Measured at 61 columns x 200 rows:
 *
 *     all 3 objects, every column filled   1.32 ms
 *     the main class only                  0.68 ms
 *     placeholder, built on access         0.77 ms   <- touched, lazy is dearer
 *     placeholder, never touched           0.01 ms   <- factor 130
 *
 * Laziness therefore pays **per table, not per column** — bookkeeping per
 * column would cost more than the filling itself (`setRawValue()` costs 49 ns).
 *
 * **3. A foreign key column carries a number, the property an object.** If the
 * same query delivers the target — `pages.sql` does for `Page::$node` — the
 * two get wired immediately. Otherwise the property stays uninitialised and
 * the id waits in the row.
 */
final class RowReader
{
    /** @var array<int, array{class-string, string, string}> */
    private array $mapped;

    /** @var array<class-string, list<int>> class => column indices */
    private array $byClass = [];

    /** @var array<class-string, array<string, class-string>> class => property => target class */
    private array $refs = [];

    /** @var array<string, ReflectionProperty> */
    private array $props = [];

    public function __construct(
        private readonly ResultMap $map,
        private readonly IdentityMap $identity,
        /** This class gets filled at once, every other one becomes a ghost. */
        private readonly ?string $eager = null,
        /**
         * Resolves relations the query did not deliver. Optional: without it
         * a row still works, its unjoined relations simply stay empty.
         */
        private readonly ?Resolver $resolver = null,
    ) {
        $this->mapped = $map->mapped();
        foreach ($this->mapped as $i => [$class, , ]) {
            $this->byClass[$class][] = $i;
        }
        // Which properties are relations? Once per plan, not per row.
        foreach (array_keys($this->byClass) as $class) {
            $table = Schema::of($class);
            $toProperty = $table->columnToProperty();
            foreach ($table->foreignKeys as $column => $fk) {
                $property = $toProperty[$column] ?? null;
                if ($property !== null) {
                    $this->refs[$class][$property] = $fk->class;
                }
            }
        }
    }

    /**
     * Read one row.
     *
     * @param list<mixed> $row  FETCH_NUM — the index is the key into the plan,
     *                          and FETCH_ASSOC would even be dangerous here:
     *                          identically named columns from two tables
     *                          silently overwrite each other there.
     * @return array<class-string, TableRow|null>
     */
    public function read(array $row): array
    {
        $result = [];
        foreach ($this->byClass as $class => $indices) {
            $result[$class] = $this->objectFor($class, $indices, $row);
        }
        $this->wire($result);
        return $result;
    }

    /** The loose values of a row — expressions, concats, subquery scalars. */
    public function loose(array $row): array
    {
        $out = [];
        foreach ($this->map->loose() as $i => $name) {
            $out[$name] = $row[$i] ?? null;
        }
        return $out;
    }

    // ------------------------------------------------------------------

    private function objectFor(string $class, array $indices, array $row): ?TableRow
    {
        $table = Schema::of($class);
        $key = $this->identityOf($class, $row);

        // No identity, no object. Either the query did not deliver the key at
        // all, or it delivered NULL — a LEFT JOIN without a hit — and building
        // something out of that would be a row that does not exist.
        if ($key === null) {
            return null;
        }

        $existing = $this->identity->get($table->name, $key);
        if ($existing !== null) {
            $this->fillGaps($existing, $class, $indices, $row);
            return $existing;
        }

        // **A composite-key row is never a ghost**, and that is not a
        // limitation but the right trade. A ghost exists to avoid filling
        // columns nobody reads, and it pays for that with an identity trick:
        // `$id` gets set and released so that looking at it does not wake the
        // object. A key made of ordinary properties has no such release — the
        // first read of `$link->tag` would materialise it anyway. And a
        // junction is two to five columns, where filling costs 49 ns per
        // column and the bookkeeping costs more than that.
        $lazy = $table->keyIsId && $this->eager !== null && $this->eager !== $class;

        $object = $lazy
            ? $this->buildGhost($class, $indices, $row)
            : $this->buildEager($class, $indices, $row);

        return $this->identity->put($table->name, $key, $object);
    }

    /**
     * The identity of the row in front of us, straight from the raw values.
     *
     * Deliberately not via the object: at this point it may not exist yet, and
     * asking an object that does exist would mean reading properties — which
     * on a sleeping ghost is exactly the thing that wakes it.
     */
    private function identityOf(string $class, array $row): ?string
    {
        $indices = $this->map->keyIndicesOf($class);
        if ($indices === null) {
            return null;
        }
        $values = [];
        foreach ($indices as $i) {
            $value = $row[$i] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            $values[] = $value;
        }
        return Identity::hash($values);
    }

    /**
     * Complete a row that already exists from a **richer** query.
     *
     * The case arises as soon as two queries deliver the same row and the
     * second one has more columns: `PageTreeLevel.sql` brings four columns of a
     * rewritepath along, `PageTreeLevel.rewritepaths.sql` all of them. Without this
     * completion identity wins and the extra values fall away — they were
     * fetched, paid for, and the object stays incomplete. Access then fails
     * with "must not be accessed before initialization", and the next
     * completion fetches what was already there.
     *
     * Only **what is still missing** gets filled. A property that holds a
     * value stays untouched: it could have been changed since loading, and
     * loading must not overwrite a change. That makes this a completion, not
     * an update — whoever wants to read afresh clears the identity map.
     *
     * Foreign keys count as gaps too. A relation the first query did not
     * deliver gets the id this one brings — into `$refIds`, exactly as a first
     * read would have filed it (`apply()`); `wire()` then connects it if the
     * target sits in the same row. Skipping them, as this method once did,
     * threw the id away: `relationId()` answered NULL ("no relation") for a
     * column the database had just delivered, and `__get__()` fetched the
     * whole row again to find it.
     *
     * A sleeping ghost is not touched at all. It already carries the values of
     * its own row in its initialiser, and any method call on it would wake it.
     */
    private function fillGaps(TableRow $object, string $class, array $indices, array $row): void
    {
        if (self::isSleeping($object)) {
            return;
        }
        foreach ($indices as $i) {
            [, $property, ] = $this->mapped[$i];
            if ($property === 'id' || $object->knows($property)) {
                continue;   // the key is set; a known value may have been changed since
            }
            if (isset($this->refs[$class][$property])) {
                if ($object->refId($property) === null) {
                    $raw = $row[$i];
                    if ($raw !== null && (int) $raw > 0) {
                        $object->addRefIds([$property => (int) $raw]);
                    } else {
                        $this->prop($class, $property)->setRawValue($object, null);   // points nowhere — a fact
                    }
                }
                $object->markKnown($property);
                continue;
            }
            $this->prop($class, $property)->setRawValue($object, Extractor::fromDb($class, $property, $row[$i]));
            $object->markKnown($property);
        }
    }

    private function buildEager(string $class, array $indices, array $row): TableRow
    {
        /** @var TableRow $object */
        $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $refs = $this->apply($object, $class, $indices, $row);
        $object->setRefIds($refs);
        $object->setResolver($this->resolver);
        $object->markLoaded(readOnly: $this->map->keyIndicesOf($class) === null, delivered: $this->deliveredBy($indices));
        return $object;
    }

    /**
     * A lazy ghost: a real, typed object of its class whose content only
     * arrives on first access.
     *
     * The difference to a placeholder is that `$node instanceof Node` holds
     * and the type of `public ?Node $parentNode` stays satisfied.
     */
    private function buildGhost(string $class, array $indices, array $row): TableRow
    {
        $rc = new ReflectionClass($class);
        $values = [];
        foreach ($indices as $i) {
            [, $property, ] = $this->mapped[$i];
            $values[$property] = $row[$i];
        }
        $readOnly = $this->map->keyIndicesOf($class) === null;

        /** @var TableRow $ghost */
        $ghost = $rc->newLazyGhost(function (TableRow $object) use ($class, $values, $readOnly): void {
            $refs = [];
            foreach ($values as $property => $raw) {
                if (isset($this->refs[$class][$property])) {
                    if ($raw !== null && (int) $raw > 0) {
                        $refs[$property] = (int) $raw;
                    } else {
                        $this->prop($class, $property)->setRawValue($object, null);
                    }
                    continue;   // do not assign relations as a number
                }
                $this->prop($class, $property)
                    ->setRawValue($object, Extractor::fromDb($class, $property, $raw));
            }
            $object->setRefIds($refs);
            $object->setResolver($this->resolver);
            $object->markLoaded(readOnly: $readOnly, delivered: [...array_keys($values), 'id']);
        });

        // Set the id at once without waking: it is the identity, and a look at
        // it must not materialise the object.
        $idIndex = $this->map->primaryIndexOf($class);
        if ($idIndex !== null) {
            $rp = new ReflectionProperty($class, 'id');
            $rp->setRawValueWithoutLazyInitialization($ghost, (int) $row[$idIndex]);
            $rp->skipLazyInitialization($ghost);
        }
        return $ghost;
    }

    /**
     * Write values into an object and collect the foreign key ids.
     *
     * @return array<string, int>
     */
    private function apply(TableRow $object, string $class, array $indices, array $row): array
    {
        $refs = [];
        foreach ($indices as $i) {
            [, $property, ] = $this->mapped[$i];
            $raw = $row[$i];

            if (isset($this->refs[$class][$property])) {
                if ($raw !== null && (int) $raw > 0) {
                    $refs[$property] = (int) $raw;
                } else {
                    // Delivered and empty: the relation points nowhere, and
                    // that is a known fact, not a missing column. Written as
                    // NULL so the read fallback does not go and look for it.
                    $this->prop($class, $property)->setRawValue($object, null);
                }
                continue;
            }
            $this->prop($class, $property)
                ->setRawValue($object, Extractor::fromDb($class, $property, $raw));
        }
        return $refs;
    }

    /**
     * Wire up relations within one row.
     *
     * This is the gain the legacy code does not have: `pages.sql` delivers
     * page **and** node in the same row, so `$page->node` costs no query
     * afterwards. If the id points at something that is not in this row, the
     * property stays uninitialised — which is the more honest answer than a
     * NULL that would mean "does not exist".
     *
     * @param array<class-string, TableRow|null> $objects
     */
    private function wire(array $objects): void
    {
        foreach ($objects as $class => $object) {
            if ($object === null || ($this->refs[$class] ?? []) === []) {
                continue;
            }
            // Do not touch a sleeping ghost. `refId()` is a method call, and
            // **every** method call wakes it — unlike reading a property that
            // `skipLazyInitialization()` has released. That is exactly what
            // the first version foundered on: the side tables were created
            // lazily and woken up one row later all the same. The ghost
            // carries its foreign keys regardless, its initialiser sets them.
            if (self::isSleeping($object)) {
                continue;
            }
            foreach ($this->refs[$class] ?? [] as $property => $targetClass) {
                $target = $objects[$targetClass] ?? null;
                if ($target === null || $object->isModified($property)) {
                    continue;   // loading must not overwrite a change: refId() still names the loaded target
                }
                $id = $object->refId($property);
                // `$target->id` is safe: the ghost released the id through
                // `skipLazyInitialization()`, so reading it does not wake it.
                // Measured.
                if ($id === null || $target->id !== $id) {
                    continue;
                }
                $this->prop($class, $property)->setRawValue($object, $target);
                $object->markKnown($property);
            }
        }
    }

    /**
     * The properties a set of column indices delivers, and the id.
     *
     * @return list<string>
     */
    private function deliveredBy(array $indices): array
    {
        $properties = ['id'];
        foreach ($indices as $i) {
            $properties[] = $this->mapped[$i][1];
        }
        return $properties;
    }

    /** A lazy ghost that has not been materialised yet. */
    private static function isSleeping(object $o): bool
    {
        $rc = new ReflectionClass($o);
        return method_exists($rc, 'isUninitializedLazyObject') && $rc->isUninitializedLazyObject($o);
    }

    /** A ReflectionProperty once per class and property, not per cell. */
    private function prop(string $class, string $property): ReflectionProperty
    {
        return $this->props[$class . '::' . $property] ??= new ReflectionProperty($class, $property);
    }
}
