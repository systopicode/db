<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use RuntimeException;
use Systopic\Db\TableRow;

/**
 * Table name -> TableRow class.
 *
 * PDO hands the row reader the source table for every column. To turn that
 * into objects it needs the way back — and that is nowhere in the row, only in
 * the classes.
 *
 * It gets filled by hand (`register()`) or over a directory (`scan()`). A
 * directory scan is the comfortable way to start; when the modules are split
 * (section 16 of the design) each module registers its classes instead, and
 * then `db audit` has a scope as well.
 */
final class Registry
{
    /** @var array<string, class-string<TableRow>> table name => class */
    private static array $byTable = [];

    /** @var list<string> directories already scanned */
    private static array $scanned = [];

    public static function register(string $class): void
    {
        if (!is_a($class, TableRow::class, true)) {
            throw new RuntimeException("$class is not a TableRow");
        }
        $table = Schema::tableNameOf($class);
        $existing = self::$byTable[$table] ?? null;
        if ($existing !== null && $existing !== $class) {
            // Two classes on the same table cancel identity out: the same row
            // would sit in memory twice, with two sets of changes overwriting
            // each other on save. Hence an error and not a warning.
            throw new RuntimeException(
                "table `$table` is already taken by $existing, $class wants it too"
            );
        }
        self::$byTable[$table] = $class;
    }

    /** Register every TableRow class of a directory. */
    public static function scan(string $dir, string $namespace): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (in_array($dir, self::$scanned, true)) {
            return;
        }
        self::$scanned[] = $dir;

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            self::registerIfRow(rtrim($namespace, '\\') . '\\' . basename($file, '.php'));
        }
    }

    /** @var array<string, string> announced `Tables/` directories => namespace */
    private static array $sources = [];

    /**
     * A package announces its `Tables/` directory — from its Composer
     * `autoload.files`, so it is known before the first query. Scanned on
     * the first `scanTables()` without arguments (every `Scope` does that).
     *
     * Pass `__DIR__ . '/…'`: PHP hands that out resolved, so the vendor link
     * that made `scanBeside()` necessary does not get in the way.
     */
    public static function addTables(string $dir, string $namespace): void
    {
        self::$sources[rtrim(str_replace('\\', '/', $dir), '/')] = $namespace;
    }

    /**
     * Register every table of a `Tables/` directory — one folder per table,
     * the row class in it is always called `Model`:
     *
     *     Tables/Nodes/Model.php   ->  Systopic\System\Tables\Nodes\Model
     *
     * Without arguments: every directory announced with `addTables()`.
     */
    public static function scanTables(?string $dir = null, ?string $namespace = null): void
    {
        if ($dir === null) {
            foreach (self::$sources as $source => $ns) {
                self::scanTables($source, $ns);
            }
            return;
        }
        if ($namespace === null) {
            throw new RuntimeException("scanTables($dir): the namespace of the directory is missing");
        }
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (in_array($dir, self::$scanned, true)) {
            return;
        }
        self::$scanned[] = $dir;

        foreach (glob($dir . '/*/Model.php') ?: [] as $file) {
            self::registerIfRow(rtrim($namespace, '\\') . '\\' . basename(dirname($file)) . '\\Model');
        }
    }

    private static function registerIfRow(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }
        $rc = new \ReflectionClass($class);
        if ($rc->isAbstract() || !$rc->isSubclassOf(TableRow::class)) {
            return;
        }
        if (Schema::tableNameOf($class) !== '') {
            self::register($class);
        }
    }

    /**
     * Register everything that lives next to a class you already have.
     *
     * The reason this exists rather than a path: `fs::$sysRoot` points at
     * `vendor/systopic/system/`, which in this installation is a link PHP's
     * `is_dir()` will not follow — `glob()` returns nothing there while
     * `class_exists()` on the very same class returns true, because Composer
     * resolves it through its own map. A scan built on that path silently
     * registers zero classes and the panel then reports a perfectly empty
     * model.
     *
     * Reflection asks the engine where it actually loaded the file from, so
     * this works whatever the vendor layout does.
     */
    public static function scanBeside(string $class): void
    {
        $file = (new \ReflectionClass($class))->getFileName();
        if ($file === false) {
            return;
        }
        $namespace = (new \ReflectionClass($class))->getNamespaceName();
        self::scan(dirname($file), $namespace);
    }

    /** @return class-string<TableRow>|null */
    public static function classFor(string $table): ?string
    {
        return self::$byTable[$table] ?? null;
    }

    /** @return array<string, class-string<TableRow>> */
    public static function all(): array
    {
        return self::$byTable;
    }

    public static function reset(): void
    {
        self::$byTable = [];
        self::$scanned = [];
    }
}
