<?php

declare(strict_types=1);

namespace Systopic\Db;

use Closure;
use RuntimeException;
use Systopic\Db\Result\Completion;
use Systopic\Db\Result\LoadGroup;

/**
 * One request, one scope: what every query and every write shares.
 *
 *   - the connection
 *   - the identity map — one object per row, whichever query brought it
 *   - the result cache — a query run twice with the same parameters costs
 *     one statement; a write to one of its tables throws the result away
 *   - the load groups — for every loaded row the group it came from, so a
 *     column the lean query did not deliver is fetched for the whole group
 *   - the listeners — who wants to hear that a row changed, told after the
 *     commit, not in the setter
 *   - the objects built on top of rows — one facet per (class, key), so
 *     the tree and the editor look at the same `Page`
 *   - the observers — told of every result handed out and every commit
 *     (`Observer`), registered once for all scopes
 *
 * It knows nothing of the application: no language, no preview flag. Those
 * are `Queries\Params`, a value, and the two are handed around together.
 *
 * The successor of the infrastructure half of the old `Page\Scope`; the
 * parameter half became `Params`.
 */
final class Scope
{
    public readonly IdentityMap $identity;

    public readonly Results $results;

    private ?Connection $conn;

    /** @var array<int, LoadGroup> spl_object_id(TableRow) => the group it came from */
    private array $groups = [];

    /** @var array<class-string, array<string, object>> class => key => object */
    private array $objects = [];

    /** @var array<class-string, array<string, array<int, Closure>>> class => property => listeners */
    private array $listeners = [];

    private int $nextListener = 0;

    /** Statements this scope has cost. The check step reads it. */
    private int $queries = 0;

    /** @var list<Observer> for every scope of the process */
    private static array $observers = [];

    /**
     * Reads while `objectFor()` builds, one frame per build in progress.
     *
     * @var list<list<array{0: string, 1: Rows}>>
     */
    private array $capturing = [];

    /**
     * The reads an object was built from — told again on every hit, or the
     * second reader of a cached `Project` would never learn what it depends on.
     *
     * @var array<class-string, array<string, list<array{0: string, 1: Rows}>>>
     */
    private array $objectReads = [];

    /**
     * Without a connection it connects on first use — a scope for a test
     * that never touches the database can be built without one.
     */
    public function __construct(
        ?Connection $connection = null,
        ?IdentityMap $identity = null,
        ?Results $results = null,
    ) {
        $this->conn = $connection;
        $this->identity = $identity ?? new IdentityMap();
        $this->results = $results ?? new Results();
        // Without the registry the row reader is blind: `getColumnMeta()`
        // names tables, only the registry knows the class behind one. The
        // packages announce their `Tables/` with `Registry::addTables()`;
        // they are scanned here once.
        Schema\Registry::scanTables();
    }

    /** Virtual: connects on the first read when none was given. */
    public Connection $connection {
        get => $this->conn ??= Connection::default();
    }

    // ------------------------------------------------------------ default

    private static ?self $default = null;

    /**
     * The scope of this request — for the entry points that have no other
     * way to one: `UnitOfWork::save()`, a loader called from a template.
     */
    public static function default(): self
    {
        return self::$default ??= new self();
    }

    /** For tests and for a second request in the same process. */
    public static function setDefault(?self $scope): void
    {
        self::$default = $scope;
    }

    // ----------------------------------------------------------- queries

    /**
     * Run a query file — or hand back the result it produced earlier.
     *
     * Cached by file and parameters (and the completion policy, which shapes
     * the objects). Cached only until `UnitOfWork::commit()` writes one of the
     * tables it read.
     *
     * @param array<string, mixed> $params
     */
    public function rows(
        string $file,
        array $params,
        Completion $completion = Completion::Group,
        bool $cache = true,
        ?Schema\Provisioner $provisioner = null,
    ): Rows {
        $key = Results::key($file, $params, $completion->name);
        if ($cache) {
            $hit = $this->results->get($key);
            if ($hit !== null) {
                $this->read($key, $hit);
                return $hit;
            }
        }
        $rows = Rows::fromFile($file)
            ->on($this->connection)
            ->identityMap($this->identity)
            ->completion($completion)
            ->provisioning($provisioner, $file)
            ->run($params);
        $this->queries++;
        $this->track($rows->group(), $rows->records());
        $this->read($key, $rows);
        return $cache ? $this->results->put($key, $rows) : $rows;
    }

    /** A unit of work on this scope: it invalidates and announces afterwards. */
    public function uow(): UnitOfWork
    {
        return new UnitOfWork($this->connection, $this->identity, $this);
    }

    /** Rows in, one commit — the short form for what an operator handed back. */
    public function save(TableRow ...$rows): void
    {
        if ($rows === []) {
            return;
        }
        $uow = $this->uow();
        foreach ($rows as $row) {
            $uow->persist($row);
        }
        $uow->commit();
    }

    /** Rows out, one commit — `save()` for deleting. */
    public function remove(TableRow ...$rows): void
    {
        $this->write([], $rows);
    }

    /**
     * Rows in and rows out, one commit — for an operator that hands back
     * both. Inserts and updates run first, then the deletes in the order
     * given (a junction row before the row it points at).
     *
     * @param list<TableRow> $save
     * @param list<TableRow> $remove
     */
    public function write(array $save, array $remove): void
    {
        if ($save === [] && $remove === []) {
            return;
        }
        $uow = $this->uow();
        foreach ($save as $row) {
            $uow->persist($row);
        }
        foreach ($remove as $row) {
            $uow->remove($row);
        }
        $uow->commit();
    }

    /** @internal */
    public function countQuery(): void
    {
        $this->queries++;
    }

    public function queryCount(): int
    {
        return $this->queries;
    }

    // ------------------------------------------------------------ columns

    /**
     * @internal queries report which group a row came from
     * @param list<array<class-string, TableRow|null>> $records
     */
    public function track(?LoadGroup $group, array $records): void
    {
        if ($group === null) {
            return;
        }
        foreach ($records as $record) {
            foreach ($record as $object) {
                if ($object !== null) {
                    $this->groups[spl_object_id($object)] ??= $group;
                }
            }
        }
    }

    /**
     * Make sure a column is there that the lean query did not deliver.
     *
     * The way to it is the row's `LoadGroup` — so **one** statement for every
     * object of the same execution, not one per object. A row this scope did
     * not load (a ghost, a row built by hand) has no group; then the row's own
     * fallback applies — `TableRow::__get__()` fetches its row on first access.
     */
    public function need(TableRow $row, string ...$properties): void
    {
        $group = $this->groups[spl_object_id($row)] ?? null;
        if ($group === null) {
            return;
        }
        foreach ($properties as $property) {
            if ($group->complete($row, $property)) {
                $this->queries++;
            }
        }
    }

    /** The value of a column, fetched first if need be. */
    public function value(TableRow $row, string $property): mixed
    {
        $this->need($row, $property);
        return $row->$property;
    }

    // ------------------------------------------------------------ objects

    /**
     * One value per (class, key) for the length of the request.
     *
     * For what is built on top of rows and must not exist twice: the `Page`
     * of a node under a set of parameters, the assembled tag groups, the
     * settings tree. The key is the caller's — it knows what makes its object
     * unique. A commit throws all of them away (`committed()`).
     *
     * @template T
     * @param class-string $class the class that owns the value
     * @param Closure(): T $make
     * @return T
     */
    public function objectFor(string $class, string $key, Closure $make): mixed
    {
        if (self::$observers === []) {
            return $this->objects[$class][$key] ??= $make();
        }
        if (isset($this->objects[$class][$key])) {
            foreach ($this->objectReads[$class][$key] ?? [] as [$readKey, $rows]) {
                $this->read($readKey, $rows);
            }
            return $this->objects[$class][$key];
        }
        $this->capturing[] = [];
        try {
            $object = $make();
        } finally {
            $this->objectReads[$class][$key] = array_pop($this->capturing);
        }
        return $this->objects[$class][$key] = $object;
    }

    public function hasObject(string $class, string $key): bool
    {
        return isset($this->objects[$class][$key]);
    }

    // ---------------------------------------------------------- observers

    /**
     * Be told of every result handed out and every commit, in every scope of
     * the process (`Observer`). For a package at boot; the closure returned
     * takes it off again (tests).
     */
    public static function addObserver(Observer $observer): Closure
    {
        self::$observers[] = $observer;
        return static function () use ($observer): void {
            self::$observers = array_values(array_filter(self::$observers, static fn(Observer $o) => $o !== $observer));
        };
    }

    /** A result went out: to the builds in progress and to the observers. */
    private function read(string $key, Rows $rows): void
    {
        if (self::$observers === []) {
            return;
        }
        foreach (array_keys($this->capturing) as $frame) {
            $this->capturing[$frame][] = [$key, $rows];
        }
        $this->tell(static fn(Observer $observer) => $observer->read($key, $rows));
    }

    /** An observer that throws is reported, never passed on: the data is in. */
    private function tell(Closure $call): void
    {
        foreach (self::$observers as $observer) {
            try {
                $call($observer);
            } catch (\Throwable $e) {
                trigger_error('Scope observer ' . $observer::class . ': ' . $e->getMessage(), E_USER_WARNING);
            }
        }
    }

    // ---------------------------------------------------------- listeners

    /**
     * Be told when a column of any row of a table changed — after the
     * change is in the database.
     *
     *     $stop = $scope->onChange(Node::class, 'parentNode', function (Node $node, ?int $old, ?int $new) {
     *         …
     *     });
     *
     * After the commit and not in the setter: a listener that hears of a
     * change the transaction then rolled back would hold wrong data. For a
     * relation `$old` and `$new` are ids, not objects. Loading fires nothing;
     * a removal fires once more with `$new` = NULL.
     *
     * The listener lives in this scope and dies with the request; the closure
     * returned unsubscribes earlier.
     *
     * @param class-string<TableRow> $class
     * @param Closure(TableRow, mixed, mixed): void $listener
     */
    public function onChange(string $class, string $property, Closure $listener): Closure
    {
        if (!property_exists($class, $property)) {
            throw new RuntimeException("$class has no property \$$property");
        }
        $id = $this->nextListener++;
        $this->listeners[$class][$property][$id] = $listener;
        return function () use ($class, $property, $id): void {
            unset($this->listeners[$class][$property][$id]);
        };
    }

    /**
     * @internal `UnitOfWork` reports what it wrote, after the transaction held.
     *
     * @param list<TableRow> $written  rows inserted, or updated with a value that differs; changes still on them
     * @param list<TableRow> $removed  rows deleted
     */
    public function committed(array $written, array $removed): void
    {
        $tables = [];
        foreach ([...$written, ...$removed] as $row) {
            $tables[Schema\Schema::tableNameOf($row::class)] = true;
        }
        $this->results->invalidate(array_keys($tables));        // The objects built on top of results — a `Page` bound to the level
        // it was loaded on — go with them: a facet that kept its old level
        // would answer `children` from before the write. The rows stay.
        $this->objects = [];
        $this->objectReads = [];

        $this->tell(static fn(Observer $observer) => $observer->committed($written, $removed));

        foreach ($written as $row) {
            $listeners = $this->listeners[$row::class] ?? [];
            if ($listeners === []) {
                continue;
            }
            foreach ($row->changes() as $property => [$old, $new]) {
                foreach ($listeners[$property] ?? [] as $listener) {
                    $listener($row, $old, $new);
                }
            }
        }
        foreach ($removed as $row) {
            foreach ($this->listeners[$row::class] ?? [] as $property => $listeners) {
                $last = $row->snapshot($property);
                foreach ($listeners as $listener) {
                    $listener($row, $last, null);
                }
            }
        }
    }
}
