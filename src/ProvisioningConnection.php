<?php

declare(strict_types=1);

namespace Systopic\Db;

use PDOException;
use PDOStatement;
use RuntimeException;
use Systopic\Db\Schema\Provisioner;

/**
 * The retry loop around a connection.
 *
 * A statement runs. If it fails, the provisioner looks at why; if it repaired
 * something, the statement runs again. The caller notices nothing — it gets
 * its result or the original error.
 *
 * Three rules the error path needs, all three measured:
 *
 * **1 — wrap `prepare` *and* `execute`.** Where the error surfaces depends on
 * the driver: real prepares fail at `prepare()`, emulated ones at `execute()`.
 * This installation runs emulated, but a decorator that only wraps one of them
 * would break the moment that changes.
 *
 * **2 — never provision inside a transaction.** DDL commits implicitly in
 * MySQL. Measured: after an `ALTER TABLE` inside a transaction,
 * `inTransaction()` reads false, `rollBack()` throws "There is no active
 * transaction", and the `UPDATE` before it is written for good. Provisioning
 * in the middle of a `UnitOfWork::commit()` therefore makes everything so far
 * irreversible. The rule against it is simple: **if a transaction is open, the
 * provisioner rolls it back, creates, and the caller replays the whole
 * commit.** Here that means: throw `ProvisioningNeeded` and let the caller
 * decide, rather than quietly destroying its transaction.
 *
 * **3 — the loop needs an upper bound.** Six attempts cover every realistic
 * query; after that an exception of its own that says what it tried how often.
 */
final class ProvisioningConnection
{
    public const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly Connection $connection,
        private readonly Provisioner $provisioner,
    ) {}

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function provisioner(): Provisioner
    {
        return $this->provisioner;
    }

    /**
     * Run a statement, provisioning what is missing.
     *
     * @param  string|null $origin the TableRow class the query was built from
     * @throws ProvisioningNeeded  when a transaction is open — the caller has
     *                             to roll back, let it provision and replay
     */
    public function run(string $sql, array $params = [], ?string $origin = null): PDOStatement
    {
        return $this->attempt(fn(): PDOStatement => $this->connection->run($sql, $params), $sql, $origin);
    }

    public function exec(string $sql, ?string $origin = null): int
    {
        return $this->attempt(fn(): int => $this->connection->exec($sql), $sql, $origin);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params = [], ?string $origin = null): array
    {
        return $this->attempt(fn(): array => $this->connection->all($sql, $params), $sql, $origin);
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = [], ?string $origin = null): ?array
    {
        return $this->attempt(fn(): ?array => $this->connection->one($sql, $params), $sql, $origin);
    }

    public function value(string $sql, array $params = [], ?string $origin = null): mixed
    {
        return $this->attempt(fn(): mixed => $this->connection->value($sql, $params), $sql, $origin);
    }

    // ------------------------------------------------------------------

    /**
     * @template T
     * @param  callable(): T $statement
     * @return T
     */
    private function attempt(callable $statement, string $sql, ?string $origin): mixed
    {
        $tried = [];
        // One statement, one repair cycle — see Provisioner::beginCycle().
        $this->provisioner->beginCycle();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $statement();
            } catch (PDOException $e) {
                if ($this->connection->inTransaction()) {
                    // Rule 2. Provisioning here would commit the open
                    // transaction implicitly and make everything before it
                    // permanent — so the caller gets the decision instead.
                    throw new ProvisioningNeeded($sql, $e);
                }

                $countBefore = count($this->provisioner->log());
                if (!$this->provisioner->provide($e, $origin)) {
                    throw $e;   // not ours — hand it on unchanged
                }
                $tried = array_merge(
                    $tried,
                    array_slice($this->provisioner->log(), $countBefore),
                );
            }
        }

        throw new RuntimeException(sprintf(
            "provisioning gave up after %d attempts.\nquery: %s\ndone:  %s",
            self::MAX_ATTEMPTS,
            $sql,
            $tried === [] ? '(nothing)' : implode("\n       ", $tried),
        ));
    }
}
