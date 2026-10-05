<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use ReflectionClass;
use Systopic\Db\Connection;

/**
 * The migrations directory — read in the `catch`, never on the success path.
 *
 * That distinction is the whole point. Scanning a directory on every request
 * would be the fingerprint back through the back door: work on the path where
 * nothing is wrong. Here the scan happens only when the provisioner has just
 * created something, and when `db provision` runs by hand.
 *
 * The bookkeeping is one table with one row per hook that has run. With plain
 * `ADD COLUMN` it stays empty — the database is its own log there, because an
 * existing column simply does not fire 1054 again. It holds exactly the events
 * that cannot otherwise be reconstructed: hooked migrations, whose `run()`
 * leaves no trace of its own.
 *
 * **Its shape follows the house convention, and finding that out cost a broken
 * front end.** `dbConventions::getTableInfo()` matches every table whose name
 * is three characters of prefix plus a plural — `~^(.{3})_((.*?)s)$~` — and
 * `dbSchema::build()` then constructs a schema table from it, expecting a
 * `<singular>_id` primary key. A table `sys_migration_hooks` with a single
 * column `hook` matches the pattern, has no `migration_hook_id`, and takes the
 * whole site down on the next request. Not with an error message — with an
 * empty page.
 *
 * So: `migration_hook_id` and `migration_hook_name`, like every other table in
 * this installation. **Anybody adding a table here either follows that
 * convention or picks a name the pattern does not match.**
 */
final class Migrations
{
    public const TABLE  = 'sys_migration_hooks';
    public const COLUMN = 'migration_hook_name';

    /** @var array<string, list<class-string<Migration>>> hook => migrations */
    private array $byHook = [];

    private bool $scanned = false;

    /** @var list<string> */
    private array $recorded = [];

    public function __construct(
        private readonly string $directory,
        private readonly string $namespace,
    ) {}

    /**
     * The two kinds of hook, as values of their own.
     *
     * Deliberately **not** `Migration::ON_CREATE` / `Migration::ON_WIDEN`:
     * those are the constants a migration overrides, and on the base class
     * both are the empty string. Using them as discriminators made the two
     * kinds indistinguishable — every migration ended up under one key and
     * `ON_WIDEN` was never read at all.
     */
    public const CREATE = 'create';
    public const WIDEN  = 'widen';

    /**
     * Run every migration hanging on this hook.
     *
     * @param list<string> $log  gets the lines of what ran
     */
    public function runFor(
        string $hook,
        Connection $connection,
        array &$log,
        string $kind = self::CREATE,
    ): void {
        $this->scan();
        $key = $kind . '#' . $hook;
        foreach ($this->byHook[$key] ?? [] as $class) {
            if ($this->alreadyRun($connection, $class::name())) {
                continue;
            }
            (new $class())->run($connection);
            // The entry comes **after** run(): if the process dies in between,
            // the hook runs once more on restart, which is the safe direction.
            $this->record($connection, $class::name());
            $log[] = '  hook ' . $hook . ': ' . $class::name();
        }
    }

    /** Which hooks exist at all — `db provision` and the audit ask. */
    public function hooks(): array
    {
        $this->scan();
        return array_keys($this->byHook);
    }

    private function scan(): void
    {
        if ($this->scanned) {
            return;
        }
        $this->scanned = true;
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (glob(rtrim($this->directory, '/\\') . '/*.php') ?: [] as $file) {
            $class = rtrim($this->namespace, '\\') . '\\' . basename($file, '.php');
            if (!class_exists($class)) {
                require_once $file;
            }
            if (!class_exists($class) || !is_a($class, Migration::class, true)) {
                continue;
            }
            $rc = new ReflectionClass($class);
            if ($rc->isAbstract()) {
                continue;
            }
            foreach ([self::CREATE => 'ON_CREATE', self::WIDEN => 'ON_WIDEN'] as $kind => $constant) {
                $hook = (string) $rc->getConstant($constant);
                if ($hook !== '') {
                    $this->byHook[$kind . '#' . $hook][] = $class;
                }
            }
        }
    }

    // ---------------------------------------------------------- bookkeeping

    private function alreadyRun(Connection $connection, string $name): bool
    {
        if ($this->recorded === []) {
            $this->ensureTable($connection);
            $this->recorded = array_column(
                $connection->all('SELECT ' . self::COLUMN . ' FROM ' . self::TABLE),
                self::COLUMN,
            );
            // A marker so an empty table does not get read again on every call.
            $this->recorded[] = "\0loaded";
        }
        return in_array($name, $this->recorded, true);
    }

    private function record(Connection $connection, string $name): void
    {
        $connection->run(
            'INSERT IGNORE INTO ' . self::TABLE . ' (' . self::COLUMN . ') VALUES (?)',
            [$name],
        );
        $this->recorded[] = $name;
    }

    private function ensureTable(Connection $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . ' migration_hook_id int unsigned NOT NULL AUTO_INCREMENT,'
            . ' ' . self::COLUMN . ' varchar(190) NOT NULL,'
            . ' PRIMARY KEY (migration_hook_id),'
            . ' UNIQUE KEY uk_' . self::COLUMN . ' (' . self::COLUMN . ')'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
