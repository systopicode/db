<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/**
 * A column as the class declaration describes it — without SQL.
 *
 * That no SQL lives here is the whole point: the same `Column` becomes
 * `INT UNSIGNED NOT NULL` through `PlatformMySql` and `integer NOT NULL`
 * through `PlatformPostgres`. A type class (`DbTypeColumnType`) therefore
 * returns a `Column` and never a string.
 */
final class Column
{
    public function __construct(
        public readonly Kind $kind,
        public readonly ?int $length = null,
        public readonly bool $nullable = false,
        public readonly ?Index $index = null,
        /** @var list<string> the cases of a backed enum */
        public readonly array $enumCases = [],
        public readonly bool $autoIncrement = false,
        public readonly bool $unsigned = false,
        public readonly mixed $default = null,
        /** Decimal places for Kind::Decimal. */
        public readonly ?int $scale = null,
    ) {}

    /**
     * A copy with changed details.
     *
     * Nullability does not come from the type class but from the property
     * using it (`?Slug` against `Slug`). It therefore has to be settable after
     * the fact without the type class knowing about it.
     */
    public function with(
        ?bool $nullable = null,
        ?bool $unsigned = null,
        mixed $default = null,
        ?Index $index = null,
        ?int $length = null,
        ?int $scale = null,
    ): self {
        return new self(
            kind:          $this->kind,
            length:        $length   ?? $this->length,
            nullable:      $nullable ?? $this->nullable,
            index:         $index    ?? $this->index,
            enumCases:     $this->enumCases,
            autoIncrement: $this->autoIncrement,
            unsigned:      $unsigned ?? $this->unsigned,
            default:       $default  ?? $this->default,
            scale:         $scale    ?? $this->scale,
        );
    }

    /**
     * A copy without its index.
     *
     * For the leftmost column of a composite key, and only for that. A primary
     * key is an index on its own prefix, so `PRIMARY KEY (tin_tag_id,
     * tin_node_id)` already covers `tin_tag_id` — a second index there would
     * be a copy of something that exists, and `Audit` would propose creating
     * it forever because creating it never makes the comparison agree.
     *
     * `with()` cannot express this: its arguments fall back to the current
     * value when they are NULL, which is exactly the value being removed here.
     */
    public function withoutIndex(): self
    {
        return new self(
            kind:          $this->kind,
            length:        $this->length,
            nullable:      $this->nullable,
            index:         null,
            enumCases:     $this->enumCases,
            autoIncrement: $this->autoIncrement,
            unsigned:      $this->unsigned,
            default:       $this->default,
            scale:         $this->scale,
        );
    }

    /**
     * The same rule for two already rendered column definitions.
     *
     * `Provisioner` and `Audit` both have to ask "does the database still
     * contradict the declaration?", and both have a platform-rendered string
     * in hand rather than a `Column`. Without this the default makes every
     * comparison fail: a declared `DEFAULT ''` that the column does not carry
     * would look like a type deviation — and the provisioner would `MODIFY`
     * in a loop until the attempt counter stopped it. That is exactly how this
     * method came to exist.
     */
    public static function withoutDefault(string $rendered): string
    {
        return trim((string) preg_replace('~\s+DEFAULT\s+.*$~i', '', $rendered));
    }

    /**
     * Two renderings compared the way the engine would compare them.
     *
     * **Normalised, not truncated.** What this has to guarantee is that the
     * comparison *round-trips*: whatever we render, the database must report
     * back as equal once it has been applied. Where that fails, the
     * provisioner is the one that suffers — it compares, sees a difference,
     * runs a `MODIFY` that writes exactly what was already there, compares
     * again, and keeps going until the attempt counter stops it. The
     * difference is never removable, because it was never real.
     *
     * Two things are known not to round-trip on MySQL:
     *
     *     we render      tinyint(1) NULL DEFAULT 0
     *     MySQL reports  tinyint    NULL DEFAULT '0'
     *
     * The **display width** of an integer means nothing — it only told an old
     * client how many places to pad to, and 8.0.19 deprecated it. And a
     * **numeric default comes back quoted**, because `information_schema`
     * keeps every default in a text column and `SHOW CREATE TABLE` renders
     * literals with quotes to tell them from expressions.
     *
     * `varchar(8)` keeps its number, of course — there it is the length and
     * means everything. Only the integer family loses it.
     *
     * The default itself stays in the comparison. Dropping it was the older
     * answer to the same problem and it cost more than it saved: `DEFAULT 0`
     * on a column carrying a foreign key is not decoration, it is a promise
     * that every new row will point at a node that does not exist — and while
     * the comparison ignored it, nothing ever reported it.
     */
    public static function comparable(string $rendered): string
    {
        $s = trim((string) preg_replace('~\s+~', ' ', $rendered));

        $s = (string) preg_replace(
            '~\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)~i',
            '$1',
            $s,
        );

        // DEFAULT '0' and DEFAULT 0 are the same default.
        $s = (string) preg_replace(
            "~\bDEFAULT\s+'(-?\d+(?:\.\d+)?)'~i",
            'DEFAULT $1',
            $s,
        );

        return $s;
    }

    /**
     * Short form for comparing two columns — in `Audit` and in tests.
     * Deliberately without the default: in the database that has often grown
     * historically and says nothing about the structure.
     */
    public function signature(): string
    {
        $s = $this->kind->name;
        if ($this->length !== null) {
            $s .= '(' . $this->length . ($this->scale !== null ? ',' . $this->scale : '') . ')';
        }
        if ($this->enumCases) {
            $s .= '[' . implode('|', $this->enumCases) . ']';
        }
        if ($this->unsigned)      $s .= ' unsigned';
        $s .= $this->nullable ? ' null' : ' not null';
        if ($this->autoIncrement) $s .= ' auto';
        if ($this->index)         $s .= ' ' . strtolower($this->index->name);
        return $s;
    }
}
