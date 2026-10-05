<?php

declare(strict_types=1);

namespace Systopic\Db\Type;

use Stringable;
use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\Kind;

/**
 * Long text — `TEXT` in MySQL, `text` in Postgres.
 *
 * The first type class that comes out of a real need rather than an example.
 * `string` maps to `varchar(255)`, and for `page_html01` or
 * `page_meta_json_ld` that is simply wrong: those are `TEXT`. PHP cannot
 * express the difference in its type — so it gets a type of its own, exactly
 * as the design intends.
 *
 * Deliberately `Stringable` and with a public `$value`: `{$page->html01}` is
 * meant to keep working in a template without anybody having to learn
 * `->value`.
 */
final class Text implements ColumnType, Stringable
{
    public function __construct(public readonly string $value = '') {}

    public static function column(): Column
    {
        // 65,535 bytes = TEXT. Without a length it would become LONGTEXT, and
        // that is a different column: MySQL stores a larger length prefix for
        // LONGTEXT. Whoever really needs LONGTEXT declares a type class of
        // their own — then the difference sits in the type, where it belongs.
        return new Column(Kind::Text, length: 65535);
    }

    public static function fromDb(mixed $raw): static
    {
        return new static((string) ($raw ?? ''));
    }

    public function toDb(): mixed
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return trim($this->value) === '';
    }
}
