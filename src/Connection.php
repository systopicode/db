<?php

declare(strict_types=1);

namespace Systopic\Db;

use PDO;
use PDOStatement;
use RuntimeException;
use Systopic\Db\Platform\MySql;
use Systopic\Db\Platform\Platform;
use Systopic\Db\Platform\Postgres;

/**
 * Access to the database.
 *
 * The application opens the connection of the request once (`open()`, from
 * its config) and everything shares it: the same PDO instance, the same
 * session, the same session variables. Were there two connections they would
 * have two transactions, two `group_concat_max_len` and two states.
 *
 * Code that brings its own PDO (the legacy `db` in systopic/system-legacy)
 * hands it in with `setDefault()`, or takes this one over with `pdo()`.
 */
final class Connection
{
    private static ?self $default = null;

    private ?Platform $platform = null;

    /** The database name, when known (open() knows it; create() does not parse the DSN). */
    public ?string $database = null;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Opens the connection of the request and makes it the default.
     *
     * `$db`: host, name, user, pass, optional port and charset — what the
     * application has configured. Throws when there is no name or the server
     * refuses.
     *
     * @param array<string, mixed> $db
     */
    public static function open(array $db): self
    {
        $name = (string) ($db['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('no database configured');
        }
        $dsn = 'mysql:dbname=' . $name
            . ';host=' . ($db['host'] ?? 'localhost')
            . (isset($db['port']) ? ';port=' . (int) $db['port'] : '')
            . ';charset=' . ($db['charset'] ?? 'utf8');
        $connection = self::create($dsn, (string) ($db['user'] ?? 'root'), (string) ($db['pass'] ?? ''));
        $connection->database = $name;
        return self::$default = $connection;
    }


    /** Is there a connection for this request? */
    public static function isOpen(): bool
    {
        return self::$default !== null;
    }

    /** The connection of the request — after `open()` or `setDefault()`. */
    public static function default(): self
    {
        return self::$default ?? throw new RuntimeException(
            'no database connection — open() or setDefault() has not been called'
        );
    }

    /** For tests and for a second, explicitly separate connection. */
    public static function setDefault(?self $connection): void
    {
        self::$default = $connection;
    }

    /**
     * A separate, independent connection.
     *
     * Sensible as soon as the new model owns tables of its own — then
     * independence is an advantage and not a danger. **During the transition
     * it is not**, for four reasons that are all hard to find once they bite:
     *
     *   1. Two transactions. If `record` writes inside an open transaction
     *      while `TableRow` reads next to it, the new model sees the old
     *      state — and the other way round.
     *   2. Session variables apply per connection. `group_concat_max_len` is
     *      1,000,000 on the legacy connection; a new one starts at 1,024 and
     *      silently truncates the existing `.sql` files. That is why it gets
     *      set here as well.
     *   3. `LAST_INSERT_ID()` and `GET_LOCK()` are bound to the connection.
     *   4. Twice as many connections per request.
     *
     * Point 2 is cleared up here. Point 1 remains and is the actual reason to
     * stay with `default()` through phases 2 to 5.
     */
    public static function create(
        string $dsn,
        string $user,
        string $password,
        array $options = [],
    ): self {
        $pdo = new PDO($dsn, $user, $password, $options + [
            PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES  => true,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // What db::connect() always set — the legacy code and the .sql
            // files rely on both: long GROUP_CONCATs, and zero dates without
            // strict mode (ALLOW_INVALID_DATES replaces the whole sql_mode).
            $pdo->exec('SET SESSION group_concat_max_len = 1000000');
            $version = explode('-', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION))[0];
            if (version_compare($version, '5.7.0') >= 0) {
                $pdo->exec("SET @@SESSION.sql_mode = 'ALLOW_INVALID_DATES'");
            }
        }
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * The platform follows from the driver, not from configuration. A wrongly
     * configured engine would be a bug nobody notices until the first DDL goes
     * wrong.
     */
    public function platform(): Platform
    {
        return $this->platform ??= match ($driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => new MySql(),
            'pgsql' => new Postgres(),
            default => throw new RuntimeException("no platform for driver '$driver'"),
        };
    }

    private ?bool $mariaDb = null;

    /**
     * MariaDB speaks the mysql driver too, but `information_schema` answers
     * differently there - see Column::schemaDefault(). The server says it in
     * its version string ("10.11.6-MariaDB-…", or "5.5.5-10.11.6-MariaDB").
     */
    public function isMariaDb(): bool
    {
        return $this->mariaDb ??= str_contains(
            strtolower((string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION)),
            'mariadb'
        );
    }

    // ------------------------------------------------------------- executing

    /**
     * @param array<string, mixed>|list<mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        // Measuring point for .cursor/tools/tree-load-probe.php, the counterpart
        // to the one in dbStatement::__construct(). Without both the probe sees
        // only half: the legacy code runs through `db`, the new model goes
        // straight to PDO here. That is exactly what made the first measurement
        // of the rebuilt pylon tree fail — it showed zero statements while
        // `Questions` counted five more.
        if (class_exists('QueryTrace', false)) {
            \QueryTrace::note('run', [$sql, $params]);
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params !== [] ? $params : null);
        return $st;
    }

    /**
     * Several statements in one roundtrip — for `.sql` files that return
     * several result sets (see `TagGroups.sql`).
     *
     * The precondition for that is not `MULTI_STATEMENTS` but
     * `ATTR_EMULATE_PREPARES` — measured on PHP 8.5.7 / MySQL 8.4.3:
     *
     *     emulate = true,  flag irrelevant -> several sets
     *     emulate = false, flag = true     -> error 1064
     *
     * The connection runs with emulation on (see create()). Should a caller
     * pass FALSE, it can be switched on the same
     * connection and switched back afterwards; native types arrive unchanged
     * either way. So: a switch around exactly this query instead of changing
     * the application.
     */
    public function runMulti(string $sql): PDOStatement
    {
        $before = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if (!$before) {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        }
        try {
            return $this->pdo->query($sql);
        } finally {
            if (!$before) {
                $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql): int
    {
        return (int) $this->pdo->exec($sql);
    }

    public function lastInsertId(): ?int
    {
        $id = $this->pdo->lastInsertId();
        return $id === false || $id === '' ? null : (int) $id;
    }

    // ---------------------------------------------------------- transaction

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * A transaction that cleans up after itself.
     *
     * To be handled with care once the provisioner arrives: DDL commits
     * implicitly in MySQL, `inTransaction()` reads false afterwards and
     * `rollBack()` throws. Hence the rule never to provision inside a
     * transaction — the provisioner rolls back, creates, and the caller
     * replays the whole commit.
     */
    public function transactional(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);   // there is no such thing as a nested transaction
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
