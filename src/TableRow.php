<?php

declare(strict_types=1);

namespace Systopic\Db;

use Systopic\Db\Schema\Schema;

/**
 * One row of a table.
 *
 * The class structurally describes a table, an instance is a row inside it —
 * hence the name. It knows **nothing below itself**: no connection, no row
 * reader, no platform. It declares columns and holds values. That is why a
 * TableRow can be built and checked without a database.
 *
 * A column is a property that
 *   - is public,
 *   - is not static,
 *   - has hooks,
 *   - and is not virtual.
 *
 * That is the whole rule. No attribute, no comment, no second schema. A
 * virtual property (get only, no storage) is therefore computed by definition
 * and not a column:
 *
 *     public string $label { get => $this->name ?: '(untitled)'; }   // virtual
 *     public string $name = '' { set => $this->__set__(__PROPERTY__, $value); }
 *
 * Changes are collected by `__set__()` in the set hook. That is the only place a
 * change comes into being — which is why the object knows immediately what has
 * changed, without comparing snapshots. And it is why a partial object can
 * write as well: what was never loaded is not in `$modified` and the UPDATE
 * does not touch it.
 *
 * While hydrating, the row reader goes past the hook via
 * `ReflectionProperty::setRawValue()` — loading therefore marks nothing as
 * changed.
 */
abstract class TableRow
{
    /**
     * Table name **without the prefix** — 'taggroups', not 'cms_taggroups'.
     *
     * The legacy code does the same: `tagGroup::$tableName` is 'taggroups' and
     * `dbTableObs::getTableInfo()` assembles `prefix_tableName` from it. That
     * is no accident but the place where the same class points at two tables
     * in two installations: `cms_tags` in the CMS, `pwk_tags` in patchworkkit.
     *
     * Hard-wiring the prefix is exactly what would destroy that.
     */
    public const TABLE = '';

    /**
     * Table prefix, when it is not the installation's own.
     *
     * Empty means `Schema::defaultTablePrefix()`, so `DEFAULT_TABLE_PREFIX` —
     * 'cms' in the CMS, 'pwk' in patchworkkit. User management sets 'usm'
     * instead and thereby stays the same table in every installation; the
     * legacy code already does that in `user::$tablePrefix`.
     *
     * This is the seam along which the modules can later be split.
     */
    public const TABLE_PREFIX = '';

    /**
     * Column prefix without the underscore, e.g. 'node' for `node_id`.
     *
     * Legacy tables keep it: renaming 25 tables is a migration nobody needs.
     * New tables leave it out — the builder aliases, and `getColumnMeta()`
     * delivers the table anyway. One side benefit does remain though: from
     * `page_subtitle` the class can be computed back unambiguously, which the
     * provisioner needs on the error path.
     */
    public const PREFIX = '';

    /**
     * The key properties, when the key is not a single `<prefix>_id`.
     *
     * Empty means the ordinary case: the table has one key column, this layer
     * invents it, and it lands in `$id`. A junction says instead which of its
     * declared properties make up the key, in key order:
     *
     *     final class TagInNode extends TableRow {
     *         public const KEY = ['tag', 'node'];   // tin_tag_id, tin_node_id
     *     }
     *
     * **Property names, not column names**, and for the same reason the rest
     * of this layer works that way: `pwk_colors_in_colors` joins a colour to a
     * colour, and both sides would be called `color_id` if the name were
     * derived from the table name. From `$color` and `$colorJoin` they are
     * `cic_color_id` and `cic_color_join_id`, which is what the table has.
     *
     * **The order matters and is not the declaration order.**
     * `pwk_persons_at_brands` declares `(pab_brand_id, pab_person_id)` and
     * keys on `(pab_person_id, pab_brand_id)` — the leftmost key column is the
     * one an index can be read from, so it is a decision, not a formality.
     *
     * Three consequences a class taking this on should know: `$id` stays NULL
     * forever, the key values have to be set before saving (no AUTO_INCREMENT
     * fills them in afterwards), and such a row is never a lazy ghost — see
     * `RowReader::objectFor()`.
     *
     * @var list<string>
     */
    public const KEY = [];

    /**
     * The primary key.
     *
     * Without hooks — so by the rule above **not a declared column**, and that
     * is right: `Schema` creates the key column regardless, it is called the
     * same in every table, and writing it into every class would be ceremony.
     * It gets set while loading and filled in after an INSERT; NULL means
     * "this row is not in the database yet".
     */
    public ?int $id = null;

    /**
     * Changed fields, property name => new value.
     *
     * Called `modified` and not `dirty`: that term comes from buffer
     * management and carries nothing here. `record` keeps a `$fieldsModified`
     * — the same thing, a better word.
     */
    private array $modified = [];

    /** Was this row loaded from the database (rather than built fresh)? */
    private bool $persisted = false;

    /**
     * Without a primary key there is no identity and therefore no UPDATE. The
     * row reader sets this when the query did not deliver the key — then the
     * error surfaces on save instead of down in the SQL.
     */
    private bool $readOnly = false;

    /**
     * A row the application built itself starts out empty, not unset.
     *
     * The difference matters because "uninitialised" already means something
     * here: **the query did not deliver this column**. `RowReader::fillGaps()`
     * is built on it, and the error a later read produces — *must not be
     * accessed before initialization* — is the useful half of that. A partial
     * object should say so rather than answer NULL.
     *
     * A `new Setting()` is not a partial object though. Nothing was left out
     * of it; it has simply not been filled in yet, and every one of its
     * nullable columns is empty in exactly the sense NULL means. Without this
     * the first read of `$setting->name` on a row that was just created throws
     * — which is what a view does right after saving one.
     *
     * Two details that make it safe:
     *
     *   - `setRawValue()`, so nothing lands in `$modified`. The INSERT stays
     *     as short as it was and the column defaults of the table still apply.
     *   - **Nullable columns only.** A `public string $name` without a default
     *     says the row has no sensible empty state, and that error should
     *     stand.
     *
     * Rows coming out of the database never get here: `RowReader` builds them
     * with `newInstanceWithoutConstructor()` and a ghost through
     * `newLazyGhost()`, neither of which calls a constructor.
     */
    public function __construct()
    {
        foreach (self::emptyable(static::class) as $property) {
            if (!$property->isInitialized($this)) {
                $property->setRawValue($this, null);
            }
        }
    }

    /** @var array<class-string, list<\ReflectionProperty>> */
    private static array $emptyable = [];

    /**
     * The nullable columns of a class — reflected once, then remembered.
     *
     * @param class-string $class
     * @return list<\ReflectionProperty>
     */
    private static function emptyable(string $class): array
    {
        if (isset(self::$emptyable[$class])) {
            return self::$emptyable[$class];
        }
        $properties = [];
        foreach (Schema::of($class)->propertyToColumn as $property => $_column) {
            $rp = new \ReflectionProperty($class, $property);
            if ($rp->getType()?->allowsNull()) {
                $properties[] = $rp;
            }
        }
        return self::$emptyable[$class] = $properties;
    }

    /**
     * A stand-in for a row that exists and whose id is all we need.
     *
     * `$junction->node = Node::withId(17)` says "the node with id 17" without
     * fetching it — which is what a relation target usually is on the writing
     * side: the id goes into the column and nothing else about the row is
     * touched.
     *
     * It is not a lazy ghost: reading `$ref->name` gives an uninitialised
     * property, not the row — which is why it skips the constructor. A stand-in
     * does not know that the row's name is empty; it does not know the row at
     * all, and answering NULL would be a claim about a row nobody read. When
     * the object is what is wanted rather than the id, a loader or the resolver
     * is the way, and both hand back the one object of the identity map.
     *
     * Never new, because the id is set — so `UnitOfWork` would sooner update
     * it than insert it, and with nothing modified even that does nothing.
     *
     * Not called `ref()`: that name is taken by the instance method below,
     * which answers a different question — what is behind a relation this row
     * already carries.
     */
    public static function withId(int $id): static
    {
        /** @var static $row */
        $row = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $row->id = $id;
        return $row;
    }

    // ----------------------------------------------------------- changes

    /**
     * To be called inside the set hook. Returns the value so that the short
     * form `set => $this->__set__(__PROPERTY__, $value)` works.
     *
     * **It checks before it records.** The set hook is the one place a change
     * comes into being, so it is also the one place where a value that cannot
     * go into its column can still be stopped with the property name in hand.
     * What `Constraint` looks at is what PHP's type system cannot say: how
     * long a string may be, how large a number may get, whether NULL is
     * allowed — all of it out of the declaration.
     *
     * The reason it matters here and not only at the UPDATE: this installation
     * runs without `STRICT_TRANS_TABLES` (see `db.php:103`), so MySQL truncates
     * a value that is too long **silently**. Without this check a 300-character
     * label becomes a 255-character label and nobody hears about it.
     *
     * A value that does not fit the **column** is a different matter — that is
     * the provisioner's, and it widens. This is about values that do not fit
     * the **declaration**, and widening those would quietly change what the
     * model says.
     */
    protected function __set__(string $property, mixed $value): mixed
    {
        if ($this->persisted && in_array($property, static::KEY, true)) {
            // A key property of a row that is already in the database. The
            // assignment does not change this row, it names a different one —
            // and the object would be left describing a row it did not come
            // from, with its in-memory key no longer matching the one on disk.
            //
            // Refused here rather than at the UPDATE for the same reason
            // `Constraint` sits here: the set hook is where the change comes
            // into being, so it is the last moment at which nothing has
            // happened yet. `UnitOfWork` keeps a check of its own, but that
            // one protects the statement; this one protects the object.
            throw new \RuntimeException(
                static::class . ": \$$property is part of the key of a row that is already "
                . 'saved — assigning it means a different row. Remove this one and create that one.'
            );
        }
        $value = Constraint::check(static::class, $property, $value);
        // The first old value of a request is the one a listener wants to
        // hear about: whatever was assigned in between never reached the
        // database. Recorded before the hook writes the new value.
        if (!array_key_exists($property, $this->previous)) {
            $this->previous[$property] = self::comparable($this->current($property));
        }
        $this->modified[$property] = $value;
        return $value;
    }

    // ----------------------------------------------------------- changes

    /**
     * The value each changed property had before the first change of this
     * request — as `snapshot()` spells it: an id for a relation.
     *
     * The row records; it does not announce. Who wants to hear about a
     * change asks the `Scope` (`onChange()`), and the scope tells them after
     * `UnitOfWork::commit()`, from what this row remembers here. A change
     * announced in the setter would be announced for a transaction that may
     * yet roll back.
     *
     * @var array<string, mixed>
     */
    private array $previous = [];

    /**
     * What changed since the row was last saved: property => [old, new].
     *
     * Only real changes: a property assigned and then assigned its old value
     * again is not in here. Relations compare by id, so a relation re-pointed
     * at the same row is no change either — except for two rows that have no
     * id yet, which are told apart by identity.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function changes(): array
    {
        $changes = [];
        foreach ($this->previous as $property => $old) {
            $value = $this->current($property);
            $new = self::comparable($value);
            if ($old === $new && ($new !== null || !$value instanceof self)) {
                continue;
            }
            $changes[$property] = [$old, $new];
        }
        return $changes;
    }

    /** @internal after the scope has told its listeners */
    public function forgetChanges(): void
    {
        $this->previous = [];
    }

    /**
     * The value of a property as it stands, without resolving anything — an
     * id for a relation, NULL for one that points nowhere or has no id yet.
     */
    public function snapshot(string $property): mixed
    {
        return self::comparable($this->current($property));
    }

    private function current(string $property): mixed
    {
        $handle = self::$backing[static::class . '::' . $property]
            ??= new \ReflectionProperty(static::class, $property);

        return $handle->isInitialized($this)
            ? $handle->getRawValue($this)
            : $this->refId($property);
    }

    private static function comparable(mixed $value): mixed
    {
        return $value instanceof self ? $value->id : $value;
    }

    /** @return array<string, mixed> property name => value */
    public function modified(): array
    {
        return $this->modified;
    }

    public function isModified(?string $property = null): bool
    {
        return $property === null
            ? $this->modified !== []
            : array_key_exists($property, $this->modified);
    }

    /** After a successful write. */
    public function markSaved(): void
    {
        foreach ($this->modified as $property => $_) {
            $this->markKnown($property);
        }
        $this->modified = [];
        $this->persisted = true;
    }

    public function isPersisted(): bool
    {
        return $this->persisted;
    }

    /**
     * The row reader calls this after filling a loaded row.
     *
     * `$delivered` names the properties the query brought, when it did not
     * bring the whole row. From then on only those count as having a value,
     * whatever else happens to be initialised — see `$known`.
     *
     * @param list<string>|null $delivered
     */
    public function markLoaded(bool $readOnly = false, ?array $delivered = null): void
    {
        $this->modified  = [];
        $this->persisted = true;
        $this->readOnly  = $readOnly;
        $this->known     = $delivered === null ? null : array_fill_keys($delivered, true);
    }

    /**
     * The properties of a partial row that hold a value from the database —
     * NULL when that question is answered by `isInitialized()` alone: a whole
     * row, or one the application built.
     *
     * "Uninitialised means not delivered" does not hold for a property with a
     * default. `newInstanceWithoutConstructor()` skips the constructor, not
     * the defaults: `public ?string $label = ''` is initialised on every row
     * that is built, delivered or not. A partial row read first (`page_id`,
     * `page_language_iso`) kept its `''` when a later query brought the label
     * — `RowReader::fillGaps()` saw an initialised property and moved on — and
     * `__get__()` answered `''` for a label the database has. This list is what
     * both ask instead.
     *
     * @var array<string, true>|null
     */
    private ?array $known = null;

    /** @internal the row reader, the resolver and the load group, after filling a property */
    public function markKnown(string $property): void
    {
        if ($this->known !== null) {
            $this->known[$property] = true;
        }
    }

    /** @internal after the resolver fetched the whole row */
    public function markComplete(): void
    {
        $this->known = null;
    }

    /**
     * Does this row hold a value for the property — delivered, fetched or
     * assigned? Not the same as "initialised"; see `$known`.
     */
    public function knows(string $property): bool
    {
        if ($this->known === null) {
            $handle = self::$backing[static::class . '::' . $property]
                ??= new \ReflectionProperty(static::class, $property);
            return $handle->isInitialized($this);
        }
        return isset($this->known[$property]) || array_key_exists($property, $this->modified);
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    // --------------------------------------------------------- relations

    /**
     * Raw values of the foreign key columns, property name => id.
     *
     * `page_node_id` holds a number, `Page::$node` wants a `Node`. If the same
     * query delivers the node — as `pages.sql` does — the row reader wires the
     * two immediately. If it does not, the property stays **uninitialised**
     * and the id waits here until somebody needs it.
     *
     * Uninitialised and not NULL: NULL would mean "there is no node", and that
     * is something else than "we have not fetched it yet".
     */
    private array $refIds = [];

    /** @internal for the row reader */
    public function setRefIds(array $ids): void
    {
        $this->refIds = $ids;
    }

    /** @internal the resolver adds what a completing SELECT found */
    public function addRefIds(array $ids): void
    {
        $this->refIds += $ids;
    }

    /** @internal The id behind a relation that has not been resolved yet. */
    public function refId(string $property): ?int
    {
        return $this->refIds[$property] ?? null;
    }

    /**
     * The id a relation points at **now** — without resolving it.
     *
     * `refId()` answers with the id the query delivered, and keeps answering
     * with it after `$node->parentNode = $other`. That is right for the row
     * reader, which asks before anything was assigned, and wrong for a view
     * that sorts rows into collections by parent: it would file a moved node
     * under its old parent. This one looks at the assigned value first.
     *
     * NULL for "no relation" and for a target that has no id yet.
     *
     * Never NULL for "not loaded": on a partial row whose query did not
     * deliver the column, the rest of the row is fetched once — the same
     * fallback `__get__()` takes, only without resolving the target. Without
     * it a missing column read as "no relation" (a path of site 753 as one of
     * the default site).
     */
    public function relationId(string $property): ?int
    {
        $current = $this->current($property);
        if ($current === null && !$this->knows($property)
            && !$this->completed && $this->persisted && $this->resolver !== null) {
            $this->completed = true;
            if ($this->resolver->complete($this)) {
                $current = $this->current($property);
            }
        }
        return $current instanceof self ? $current->id : $current;
    }

    /**
     * Set a value **without** counting it as a change.
     *
     * For everything coming out of the database: loading is not a change. An
     * ordinary assignment would run through the set hook and land in
     * `$modified` — and the next `save()` would then write back values that
     * had just come from there.
     *
     * @internal for the Db package and the loaders in the model
     */
    public function hydrate(string $property, mixed $value): void
    {
        (new \ReflectionProperty(static::class, $property))->setRawValue($this, $value);
        unset($this->modified[$property]);
        $this->markKnown($property);
    }

    /**
     * The runtime, handed in by whoever built this row.
     *
     * Null for a row that was never loaded — `new Page()` in a test, or one
     * the application made itself. Such a row has no relations to resolve
     * either, so there is nothing to miss.
     */
    private ?Resolver $resolver = null;

    /** @internal for the row reader and the resolver */
    public function setResolver(?Resolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** @var array<string, \ReflectionProperty> class::property => handle */
    private static array $backing = [];

    /**
     * Read a relation: the loaded value, or the object behind the id.
     *
     *     public ?User $createdUser {
     *         get => $this->__get__(__PROPERTY__);
     *         set => $this->__set__(__PROPERTY__, $value);
     *     }
     *
     * **Why reflection and not `$this->$property`.** Outside the hook a
     * dynamic property access goes through the hook again — the method would
     * call itself. `getRawValue()` reaches the backing store directly, which
     * is the only way to look at it from here.
     *
     * **Why `isInitialized()` and not `??`.** They are not the same question,
     * and the difference is the one `$refIds` is built on: NULL means "there
     * is no such relation", uninitialised means "we have not fetched it yet".
     * `??` treats both as absent, so a relation that was loaded as empty
     * would ask the resolver again on every single read. Here an empty
     * relation is simply empty.
     *
     * **One caveat worth knowing.** A `get` hook shaped like this does not
     * count as touching the backing store, so a property that had *only* this
     * hook would be virtual — and a virtual property is not a column;
     * `Schema::build()` skips it. The `set` hook is what keeps it backed, and
     * every relation has one. Remove it and PHP says so at the first read:
     * *Must not read from virtual property*.
     */
    protected function __get__(string $property): mixed
    {
        $handle = self::$backing[static::class . '::' . $property]
            ??= new \ReflectionProperty(static::class, $property);

        // Initialised is not enough on a partial row: a default is initialised
        // too (see `$known`).
        if ($handle->isInitialized($this)
            && ($this->known === null || isset($this->known[$property]) || array_key_exists($property, $this->modified))) {
            return $handle->getRawValue($this);
        }
        if ($this->refId($property) !== null) {
            return $this->ref($property);   // a relation whose target id is known
        }
        // Neither a value nor an id: the query did not deliver this column.
        // Once per row the rest of it is fetched — the fallback for a partial
        // object read outside a load group (`Scope::need()` is the cheaper
        // way for a whole group). A row that never came from the database has
        // nothing to fetch and reads as empty.
        if (!$this->completed && $this->persisted && $this->resolver !== null) {
            $this->completed = true;
            if ($this->resolver->complete($this)) {
                if ($handle->isInitialized($this) && $this->knows($property)) {
                    return $handle->getRawValue($this);
                }
                if ($this->refId($property) !== null) {
                    return $this->ref($property);
                }
            }
        }
        return null;
    }

    /** Has the fallback fetch run for this row? Once is enough. */
    private bool $completed = false;

    /**
     * The object behind a relation that the query did not deliver.
     *
     * Called from the `get` hook of a relation, and only when the property is
     * still unset — a relation the query joined was written straight into the
     * backing store and never gets here.
     *
     * Three outcomes, and the difference between the first two is the whole
     * point:
     *
     *     no id in refIds      NULL   — there is no such relation
     *     id, no runtime       NULL   — nobody can fetch it; say so quietly
     *     id and runtime       object — from the identity map or a ghost
     *
     * It deliberately does not cache into the property. `Resolver` goes
     * through the identity map, so a second call returns the same object
     * anyway, and writing it here would have to bypass `$modified` — loading
     * is not a change.
     */
    protected function ref(string $property): mixed
    {
        $id = $this->refId($property);
        if ($id === null || $this->resolver === null) {
            return null;
        }
        return $this->resolver->relation(static::class, $property, $id);
    }
}
