<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use Attribute;

/**
 * What the PHP type cannot say about a column.
 *
 * The rule of this schema layer is that the declaration is the only source:
 * `?string` gives nullability, `= ''` gives the default, `?TagGroup` gives the
 * foreign key. Nobody has to write `#[Column]` over a property to state what
 * the type already states — that is the difference to Doctrine, where every
 * mapped property carries its mapping a second time.
 *
 * But a PHP type genuinely cannot carry everything. `string` says nothing
 * about how many characters, `float` nothing about decimal places, and no type
 * says "unique". Without a way to express that, `TypeMap` has to guess once
 * for everybody:
 *
 *     'string' => new Column(Kind::String, length: 255),
 *
 * and then `cms_taggroups.taggroup_color`, which is `varchar(8)`, silently
 * claims 255. A value of 40 characters passes `Constraint`, the driver raises
 * 1406, and the provisioner widens the real column — the schema drifts to
 * match a declaration nobody made deliberately.
 *
 * This attribute closes that gap, and **only** that gap:
 *
 *     #[Col(length: 8)]
 *     public ?string $color = null { set => $this->__set__(__PROPERTY__, $value); }
 *
 * **What it deliberately cannot do is change the kind.** There is no
 * `type: 'LONGTEXT'` and no `kind: Kind::Text`, for two reasons. The first is
 * that `Column` carries no SQL at all — the same description has to become
 * `longtext` on MySQL and `text` on Postgres, so a raw SQL word here would
 * break that. The second is the important one: the kind is the type's job. A
 * `string` that is really TEXT is a `Db\Type\Text`, and if the attribute could
 * overrule that, the declaration and the attribute could contradict each
 * other — which is precisely the failure this design exists to avoid. The
 * attribute refines within the kind the type chose; it never argues with it.
 *
 * Which also answers LONGTEXT without a `type:`. The platform picks the
 * concrete column from the length:
 *
 *     #[Col(length: 16_777_216)]
 *     public ?Text $body { … }        // -> longtext on MySQL, text on Postgres
 *
 * Read once per class in `Schema::build()` and cached with the rest of the
 * table, so it costs nothing per row. Attributes are part of the grammar, not
 * comments — no file is parsed to find them, and `opcache.save_comments=0`
 * does not make them disappear the way it does a docblock.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Col
{
    public function __construct(
        /** Characters for a string, bytes for a text, digits for a decimal. */
        public readonly ?int $length = null,
        /** Decimal places — only meaningful for `Kind::Decimal`. */
        public readonly ?int $scale = null,
        /** An index on this column. No PHP type can express this. */
        public readonly ?Index $index = null,
        /**
         * A non-negative integer column.
         *
         * PHP's `int` is signed and has no unsigned counterpart, so a column
         * that is `int unsigned` cannot be declared by the type. Relations get
         * it automatically — a primary key is unsigned in this schema — but a
         * plain counter like `rewritepath_livecount` has to say so.
         *
         * It refines `Kind::Int`; it does not change it. Postgres has no
         * unsigned at all and widens the range instead, which is exactly why
         * this is a hint to the platform rather than a piece of SQL.
         */
        public readonly ?bool $unsigned = null,
        /**
         * The column name, when it does not follow the convention.
         *
         * Taken literally: a relation does **not** get `_id` appended to it,
         * because an override that still gets rewritten would be worse than
         * no override at all.
         */
        public readonly ?string $name = null,
    ) {}
}
