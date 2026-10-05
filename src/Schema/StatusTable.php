<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/** One table, both sides. */
final class StatusTable
{
    /** @param list<StatusColumn> $columns */
    public function __construct(
        public readonly string $name,
        /** @var class-string|null the TableRow that describes it, if any */
        public readonly ?string $class,
        public readonly bool $exists,
        public readonly int $rowCount,
        public readonly array $columns,
        public readonly int $differences,
    ) {}

    /**
     * The class without its namespace — what a panel wants to print.
     *
     * Every row class is called `Model` and sits in the folder of its table,
     * so the folder goes along: `Pages\Model`, not a column of `Model`s.
     */
    public string $shortClass {
        get {
            if ($this->class === null) {
                return '';
            }
            $parts = explode('\\', $this->class);
            $short = array_pop($parts);
            return $short === 'Model' && $parts !== [] ? end($parts) . '\\Model' : $short;
        }
    }

    public bool $hasModel {
        get => $this->class !== null;
    }
}
