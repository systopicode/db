<?php

declare(strict_types=1);

namespace Systopic\Db;

use PDO;
use RuntimeException;
use Systopic\Db\Result\Completion;
use Systopic\Db\Result\LoadGroup;
use Systopic\Db\Result\ResultMap;
use Systopic\Db\Result\ResultSet;
use Systopic\Db\Result\RowReader;
use Systopic\Db\Schema\Provisioner;

/**
 * The entry point: run a query and get objects out of it.
 *
 * The place where the existing `.sql` files live on unchanged. `pages.sql`
 * delivers 68 columns out of three tables, and that is not a problem but the
 * normal case: the plan takes it apart, the row reader turns it into `Page`,
 * `Node` and `Rewritepath`, and what belongs to no table — `query_result`,
 * `keywords`, the `*_concat` columns — stays attached to the result as a
 * loose value.
 */
final class Rows implements \IteratorAggregate, \Countable
{
    /** @var array<string, ResultMap> fingerprint => plan */
    private static array $plans = [];

    private string $sql = '';
    private ?string $source = null;
    private ?string $eager = null;
    private Completion $policy = Completion::Group;
    private array $aliases = [];
    private ?Connection $connection = null;

    /** @var list<array<class-string, TableRow|null>> */
    private array $records = [];

    /** @var list<array<string, mixed>> */
    private array $loose = [];

    /** @var list<ResultSet> every result set of this execution */
    private array $sets = [];

    private ?LoadGroup $group = null;
    private ?ResultMap $map = null;
    private ?IdentityMap $identity = null;

    private function __construct() {}

    public static function fromSql(string $sql): self
    {
        $r = new self();
        $r->sql = $sql;
        return $r;
    }

    /** A `.sql` file from the legacy code, unchanged. */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("query file missing: $path");
        }
        $r = new self();
        $r->sql = (string) file_get_contents($path);
        $r->source = $path;
        return $r;
    }

    /** Which class gets filled at once; every other one becomes a ghost. */
    public function eager(?string $class): self
    {
        $this->eager = $class;
        return $this;
    }

    public function completion(Completion $policy): self
    {
        $this->policy = $policy;
        return $this;
    }

    /**
     * Alias => real table name.
     *
     * For `FROM cms_pages p`, `getColumnMeta()` returns the alias `p`, and the
     * registry does not know it. For queries that do this the mapping is given
     * once here instead of being guessed per row.
     */
    public function aliases(array $aliases): self
    {
        $this->aliases = $aliases;
        return $this;
    }

    public function on(Connection $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    public function identityMap(IdentityMap $map): self
    {
        $this->identity = $map;
        return $this;
    }

    /**
     * Let a provisioner repair what this query trips over.
     *
     * **Opt-in, deliberately.** Turning it on for every query would make a
     * missing column invisible everywhere at once, and phase 6 is meant to
     * arrive one caller at a time. A loader that asks for it gets the retry
     * loop; everything else keeps failing loudly, which during a rebuild is
     * the more useful behaviour.
     */
    public function provisioning(?Provisioner $provisioner, ?string $origin = null): self
    {
        $this->provisioner = $provisioner;
        $this->origin = $origin;
        return $this;
    }

    private ?Provisioner $provisioner = null;

    /** The TableRow class this query was built from, when it is one. */
    private ?string $origin = null;

    // ------------------------------------------------------------------

    public function run(array $params = []): self
    {
        $conn = $this->connection ??= Connection::default();
        $this->identity ??= new IdentityMap();

        [$sql, $bound] = self::prepare($this->sql, $params);

        // Measuring point for .cursor/tools/tree-load-probe.php — here and not in
        // Connection::run(), because Rows does not take that detour.
        if (class_exists('QueryTrace', false)) {
            \QueryTrace::note('rows', [$this->source ?? $sql, $bound]);
        }
        $st = $this->execute($conn, $sql, $bound);

        $first = true;
        do {
            if ($st->columnCount() === 0) {
                continue;   // a statement without a result, a SET for instance
            }

            // The plan hangs off the sequence of columns, not off the query —
            // the same file delivers the same columns on every run.
            $fp  = ResultMap::fingerprint($st);
            $map = self::$plans[$fp] ??= ResultMap::of($st, $this->aliases);

            // One resolver per execution: it carries the identity map of this
            // result, so a relation resolved from a row lands in the same map
            // the row itself came out of.
            $resolver ??= new Resolver($conn, $this->identity);
            $reader = new RowReader($map, $this->identity, $this->eager, $resolver);
            $group  = new LoadGroup($map, $conn, $this->policy);

            $records = [];
            $loose   = [];
            while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                $objects = $reader->read($row);
                foreach ($objects as $object) {
                    if ($object !== null) {
                        $group->add($object);
                    }
                }
                $records[] = $objects;
                $loose[]   = $reader->loose($row);
            }

            $this->sets[] = new ResultSet($map, $records, $loose);

            // The first result set is the one all(), first() and records()
            // refer to without further ado — so the common case stays as
            // simple as it was.
            if ($first) {
                $this->map     = $map;
                $this->group   = $group;
                $this->records = $records;
                $this->loose   = $loose;
                $first = false;
            }
        } while ($st->nextRowset());

        return $this;
    }

    /**
     * Prepare and execute — and, when a provisioner is set, do it again after
     * it has repaired something.
     *
     * Both calls are inside the `try`, because where the error surfaces
     * depends on the driver: real prepares fail at `prepare()`, emulated ones
     * at `execute()`. This installation runs emulated; wrapping only one of
     * them would break the day that changes.
     *
     * Not inside a transaction — DDL commits implicitly in MySQL, so
     * provisioning there would make everything so far permanent. The caller
     * gets `ProvisioningNeeded` and decides.
     */
    private function execute(Connection $conn, string $sql, array $bound): \PDOStatement
    {
        $attempts = $this->provisioner === null ? 1 : ProvisioningConnection::MAX_ATTEMPTS;
        // One statement, one repair cycle: what was already attempted for this
        // query must not be attempted again, and what was attempted for an
        // earlier one must not count against it.
        $this->provisioner?->beginCycle();

        for ($attempt = 1; ; $attempt++) {
            try {
                $st = $conn->pdo()->prepare($sql);
                $st->execute($bound !== [] ? $bound : null);
                return $st;
            } catch (\PDOException $e) {
                if ($this->provisioner === null || $attempt >= $attempts) {
                    throw $e;
                }
                if ($conn->inTransaction()) {
                    throw new ProvisioningNeeded($sql, $e);
                }
                if (!$this->provisioner->provide($e, $this->origin)) {
                    throw $e;   // not ours — hand it on unchanged
                }
            }
        }
    }

    /**
     * Every result set.
     *
     * A `.sql` file may contain several statements; `TagGroups.sql`
     * delivers three in one roundtrip. The precondition is
     * `ATTR_EMULATE_PREPARES` — not `MULTI_STATEMENTS`, as one would suspect.
     * In this installation it is TRUE regardless.
     *
     * @return list<ResultSet>
     */
    public function sets(): array
    {
        return $this->sets;
    }

    public function set(int $index): ?ResultSet
    {
        return $this->sets[$index] ?? null;
    }

    /**
     * Prepare the parameters.
     *
     * Two quirks of the legacy code are reproduced here:
     *
     *  - **An array gets substituted textually.** `IN (:ids)` cannot be bound;
     *    `dbStatement` has always replaced it via `str_replace` before the
     *    `prepare`. That is also why the same placeholder may appear several
     *    times, which `TagGroups.sql` makes use of.
     *  - **Only used parameters get bound.** A `.sql` file often declares more
     *    placeholders at the end than the concrete call needs; PDO would
     *    complain about the surplus ones.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function prepare(string $sql, array $params): array
    {
        $bound = [];
        foreach ($params as $name => $value) {
            $placeholder = ':' . ltrim((string) $name, ':');
            if (is_array($value)) {
                $list = $value === []
                    ? 'NULL'
                    : implode(',', array_map(static fn($v) => (int) $v, $value));
                $sql = str_replace($placeholder, $list, $sql);
                continue;
            }
            $bound[$placeholder] = $value;
        }

        // What does not occur is not bound. The comparison runs over a word
        // boundary so that :id does not match :ids.
        foreach (array_keys($bound) as $placeholder) {
            if (!preg_match('~' . preg_quote($placeholder, '~') . '\b~', $sql)) {
                unset($bound[$placeholder]);
            }
        }
        return [$sql, $bound];
    }

    // ------------------------------------------------------------------

    /** @return list<TableRow> every object of a class, without repetitions */
    public function all(string $class): array
    {
        $out = [];
        foreach ($this->records as $record) {
            $object = $record[$class] ?? null;
            if ($object !== null && !in_array($object, $out, true)) {
                $out[] = $object;
            }
        }
        return $out;
    }

    public function first(string $class): ?TableRow
    {
        foreach ($this->records as $record) {
            if (($record[$class] ?? null) !== null) {
                return $record[$class];
            }
        }
        return null;
    }

    /** @return list<array<class-string, TableRow|null>> */
    public function records(): array
    {
        return $this->records;
    }

    /** The loose values per row — expressions, concats, subquery scalars. */
    public function looseValues(): array
    {
        return $this->loose;
    }

    public function group(): ?LoadGroup
    {
        return $this->group;
    }

    public function map(): ?ResultMap
    {
        return $this->map;
    }

    public function identity(): ?IdentityMap
    {
        return $this->identity;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->records);
    }

    public function count(): int
    {
        return count($this->records);
    }

    /** For tests only: clear the plan cache. */
    public static function forgetPlans(): void
    {
        self::$plans = [];
    }
}
