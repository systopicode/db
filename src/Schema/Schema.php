<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use Systopic\Db\TableRow;

/**
 * Derive a table from a TableRow class.
 *
 * Cold path: the result is built once per class and then held. Reflection is
 * not expensive enough to make a problem of it, but expensive enough not to do
 * it per row.
 */
final class Schema
{
    /** @var array<class-string, Table> */
    private static array $cache = [];

    private static ?string $defaultTablePrefix = null;

    public static function of(string $class): Table
    {
        return self::$cache[$class] ??= self::build($class);
    }

    /** For tests and the audit run only. */
    public static function forget(?string $class = null): void
    {
        if ($class === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$class]);
        }
    }

    private static function build(string $class): Table
    {
        if (!is_a($class, TableRow::class, true)) {
            throw new RuntimeException("$class is not a TableRow");
        }
        $rc = new ReflectionClass($class);
        if ($rc->isAbstract()) {
            throw new RuntimeException("$class is abstract and describes no table");
        }

        $tableName = (string) $rc->getConstant('TABLE');
        if ($tableName === '') {
            throw new RuntimeException("$class has no const TABLE");
        }
        $prefixConst = (string) $rc->getConstant('PREFIX');
        $prefix = $prefixConst !== '' ? $prefixConst . '_' : '';

        // Which properties make up the key? Empty means the ordinary case:
        // the key is not declared, it is a given — every table has it, it is
        // always called the same, and writing it into every class would be
        // ceremony. A junction says instead which of its properties it is
        // keyed on, and then nothing gets invented.
        $declaredKey = $rc->getConstant('KEY');
        if (!is_array($declaredKey)) {
            throw new RuntimeException("$class has a const KEY that is not a list of property names");
        }

        $columns = [];
        if ($declaredKey === []) {
            $columns[$prefix . 'id'] = new Column(
                Kind::Int, autoIncrement: true, unsigned: true
            );
        }

        $foreignKeys = [];
        $propertyToColumn = [];

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            // THE rule: public, not static, has hooks, not virtual.
            if ($p->isStatic() || $p->getHooks() === [] || $p->isVirtual()) {
                // A #[Col] on something that is not a column would sit there
                // looking effective and do nothing. Loud, not silent.
                if (self::colOf($p) !== null) {
                    throw new RuntimeException(
                        "\${$p->getName()} in $class carries #[Col] but is no column — "
                        . 'only a public, non-static, hooked, non-virtual property becomes one'
                    );
                }
                continue;
            }

            $col = self::colOf($p);

            $type = $p->getType();
            if (!$type instanceof ReflectionNamedType) {
                throw new RuntimeException(
                    "\${$p->getName()} in $class has a union or intersection type — "
                    . 'a column carries exactly one type'
                );
            }

            [$column, $target] = TypeMap::map($type, $p->getName());

            // An explicit name is taken literally — including the `_id` of a
            // relation, which the override is then responsible for itself.
            // The foreign key is registered either way: renaming a column does
            // not stop it pointing at another table.
            $name = $col?->name ?? $prefix . self::snake($p->getName());
            if ($target !== null) {
                if ($col?->name === null) {
                    // A relation becomes the id column: $parentNode -> node_parent_node_id
                    $name .= '_id';
                }
                // Deliberately NOT Schema::of($target): `Node::$parentNode` is
                // a Node, and the cache only stands once build() has
                // finished — that would be an endless loop. Table name and key
                // column of the target are in its constants anyway, and for
                // that the whole table is not needed.
                $foreignKeys[$name] = new ForeignKey(
                    column:     $name,
                    table:      self::tableNameOf($target),
                    references: self::primaryOf($target),
                    class:      $target,
                );
            }

            if (isset($columns[$name])) {
                throw new RuntimeException("column `$name` in $class is assigned twice");
            }

            // Order of precedence: the type decides the kind, the declaration
            // decides nullability and default, the attribute refines the rest.
            $columns[$name] = $column->with(
                nullable: $type->allowsNull(),
                default:  self::defaultOf($p),
                unsigned: $col?->unsigned,
                length:   $col?->length,
                scale:    $col?->scale,
                index:    $col?->index,
            );
            $propertyToColumn[$p->getName()] = $name;
        }

        [$key, $keyProperties, $columns] = $declaredKey === []
            ? [[$prefix . 'id'], ['id'], $columns]
            : self::keyOf($class, $declaredKey, $propertyToColumn, $columns);

        return new Table(
            name:             self::tableNameOf($class),
            class:            $class,
            key:              $key,
            columns:          $columns,
            foreignKeys:      $foreignKeys,
            propertyToColumn: $propertyToColumn,
            prefix:           $prefixConst,
            keyProperties:    $keyProperties,
        );
    }

    /**
     * Turn `const KEY` into key columns — and check it while doing so.
     *
     * Two things happen here that are worth naming.
     *
     * **A key column is NOT NULL, whatever the declaration says.** The
     * relations are declared `?Tag` like every other relation in this model,
     * because that is what reading one has to allow for; but a column that is
     * part of the primary key cannot hold NULL, and no engine would accept the
     * DDL. This is not the attribute overruling the type — it is the same
     * statement the word "key" already makes. `Constraint` then rejects a NULL
     * assignment at the set hook, which is where it can still be reported.
     *
     * **The order is the declared order**, not the order the properties happen
     * to appear in. `pwk_persons_at_brands` declares its columns as
     * `(pab_brand_id, pab_person_id)` and keys on the other way round.
     *
     * @param  list<string>          $declaredKey
     * @param  array<string, string> $propertyToColumn
     * @param  array<string, Column> $columns
     * @return array{0: list<string>, 1: list<string>, 2: array<string, Column>}
     */
    private static function keyOf(
        string $class,
        array $declaredKey,
        array $propertyToColumn,
        array $columns,
    ): array {
        $key = [];
        foreach ($declaredKey as $property) {
            if (!is_string($property)) {
                throw new RuntimeException("const KEY in $class holds something that is not a property name");
            }
            $column = $propertyToColumn[$property] ?? null;
            if ($column === null) {
                throw new RuntimeException(
                    "const KEY in $class names \$$property, which is not a column — "
                    . 'a key property has to be public, non-static, hooked and non-virtual'
                );
            }
            if (in_array($column, $key, true)) {
                throw new RuntimeException("const KEY in $class names `$column` twice");
            }
            $key[] = $column;

            // A relation carries `Index::Plain` from `TypeMap`, which is right
            // for an ordinary foreign key and wrong for the **leftmost** key
            // column: the primary key already indexes its own prefix. The
            // others keep it, and that matters — `cms_tags_in_nodes` has no
            // index on `tin_node_id` today, so "which tags hang on this node"
            // reads the whole table.
            $columns[$column] = count($key) === 1
                ? $columns[$column]->with(nullable: false)->withoutIndex()
                : $columns[$column]->with(nullable: false);
        }
        return [$key, array_values($declaredKey), $columns];
    }

    /**
     * The `#[Col]` of a property, when it carries one.
     *
     * `getAttributes()` reads what the compiler stored with the class, so no
     * file is opened and nothing is tokenised. `newInstance()` only runs for a
     * property that actually has the attribute — everything else costs one
     * empty array.
     */
    private static function colOf(ReflectionProperty $p): ?Col
    {
        $found = $p->getAttributes(Col::class);
        return $found === [] ? null : $found[0]->newInstance();
    }

    /**
     * The default sits in the declaration, where it belongs:
     *
     *     public int $ordering = 0 { set => … }
     *
     * Objects and enums as a default are not carried over — no engine could
     * express them as a DEFAULT.
     */
    private static function defaultOf(ReflectionProperty $p): mixed
    {
        if (!$p->hasDefaultValue()) {
            return null;
        }
        $v = $p->getDefaultValue();
        return is_scalar($v) ? $v : null;
    }

    /**
     * The full table name: prefix plus declared name.
     *
     * Kept apart from `build()` so that self-references and cycles between
     * classes do not run into an endless loop — `Node::$parentNode` is a Node.
     *
     * The separation is not cosmetic. The same `Tag` class points at
     * `cms_tags` in the CMS and at `pwk_tags` in patchworkkit — checked, both
     * installations have these tables with their own prefix. Were `cms_tags`
     * in the constant, the class would be nailed to one installation and the
     * later module split would be blocked.
     *
     * `usm_users` shows the other side: user management sets
     * `TABLE_PREFIX = 'usm'` and is therefore called the same everywhere,
     * regardless of which application uses it.
     */
    public static function tableNameOf(string $class): string
    {
        $name = (string) constant($class . '::TABLE');
        if ($name === '') {
            throw new RuntimeException("$class has no const TABLE");
        }
        $prefix = (string) constant($class . '::TABLE_PREFIX') ?: self::defaultTablePrefix();
        return $prefix !== '' ? $prefix . '_' . $name : $name;
    }

    /**
     * The prefix of this installation.
     *
     * Comes from `DEFAULT_TABLE_PREFIX`, which index.php sets anyway — the
     * same source `dbTableObs::getTableInfo()` has always read from. Two
     * truths about which table is meant would be one too many.
     */
    public static function defaultTablePrefix(): string
    {
        return self::$defaultTablePrefix
            ??= (defined('DEFAULT_TABLE_PREFIX') ? (string) DEFAULT_TABLE_PREFIX : 'cms');
    }

    /** For tests and for looking into a foreign installation. */
    public static function setDefaultTablePrefix(?string $prefix): void
    {
        self::$defaultTablePrefix = $prefix;
        self::$cache = [];   // the cached table names no longer hold
    }

    public static function primaryOf(string $class): string
    {
        $prefix = (string) constant($class . '::PREFIX');
        return ($prefix !== '' ? $prefix . '_' : '') . 'id';
    }

    /** ordering -> ordering, parentNode -> parent_node, isTrashed -> is_trashed */
    private static function snake(string $s): string
    {
        return strtolower((string) preg_replace('~(?<!^)[A-Z]~', '_$0', $s));
    }
}
