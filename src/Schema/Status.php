<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use Systopic\Db\Connection;

/**
 * The database and the model side by side — as data, not as markup.
 *
 * `Audit` answers "what would have to happen"; this answers "what does it look
 * like right now". Two questions, two classes, but **one rule**: both compare
 * through `Column::comparable()`, so the status panel can never show a row as
 * matching that the audit then offers to change, or the other way round. That
 * is the whole reason this is not simply built inside the template.
 *
 * Nothing here knows about HTML. The panel decides that orange means "will be
 * changed" and green means "this is the reference"; all it gets from here is
 * which side a difference is on and what kind it is.
 */
final class Status
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * One entry per table, model and database together.
     *
     * Tables the model does not describe are included as well — with an empty
     * reference side. Leaving them out would make the panel say the schema is
     * clean while a third of it goes unmentioned.
     *
     * @return list<StatusTable>
     */
    public function tables(): array
    {
        $byTable = Registry::all();
        ksort($byTable);

        $out = [];
        $seen = [];

        foreach ($byTable as $name => $class) {
            $seen[$name] = true;
            $out[] = $this->compare($name, $class);
        }

        // Everything else in the database, in the order it appears there.
        foreach ($this->tableNames() as $name) {
            if (!isset($seen[$name])) {
                $out[] = $this->compare($name, null);
            }
        }

        usort($out, static fn(StatusTable $a, StatusTable $b) => strcmp($a->name, $b->name));
        return $out;
    }

    private function compare(string $name, ?string $class): StatusTable
    {
        $actual = $this->columnsOf($name);
        $table  = $class === null ? null : Schema::of($class);
        $platform = $this->connection->platform();

        /** @var array<string, string> column => rendered declaration */
        $declared = [];
        if ($table !== null) {
            foreach ($table->columns as $column => $definition) {
                $declared[$column] = $platform->renderColumn($definition);
            }
        }

        // The order of the rows follows the declaration where there is one,
        // because that is the side somebody is comparing against. Columns the
        // model does not know are appended in the order the database has them.
        $order = $declared === [] ? array_keys($actual) : array_keys($declared);
        foreach (array_keys($actual) as $column) {
            if (!in_array($column, $order, true)) {
                $order[] = $column;
            }
        }

        $rows = [];
        $differences = 0;
        foreach ($order as $column) {
            $left  = $actual[$column]['rendered'] ?? null;
            $right = $declared[$column] ?? null;

            $state = match (true) {
                $left === null                 => StatusState::OnlyDeclared,
                $right === null && $table !== null => StatusState::OnlyInDatabase,
                $right === null                => StatusState::Unknown,
                Column::comparable($left) !== Column::comparable($right)
                                               => StatusState::Different,
                default                        => StatusState::Same,
            };
            if ($state !== StatusState::Same && $state !== StatusState::Unknown) {
                $differences++;
            }

            $rows[] = new StatusColumn($column, $left, $right, $state);
        }

        return new StatusTable(
            name: $name,
            class: $class,
            exists: $actual !== [],
            rowCount: $actual === [] ? 0 : $this->rowCount($name),
            columns: $rows,
            differences: $differences,
        );
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        return array_column($this->connection->all(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        ), 'TABLE_NAME');
    }

    /**
     * The same rendering `Audit` uses — deliberately copied in shape, so the
     * two produce the same string for the same column.
     *
     * @return array<string, array<string, mixed>>
     */
    private function columnsOf(string $table): array
    {
        $out = [];
        foreach ($this->connection->all(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, EXTRA, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$table],
        ) as $row) {
            $row['COLUMN_DEFAULT'] = Column::schemaDefault($row['COLUMN_DEFAULT'], $this->connection->isMariaDb());
            $rendered = $row['COLUMN_TYPE'] . ($row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL');
            if (str_contains((string) $row['EXTRA'], 'auto_increment')) {
                $rendered .= ' AUTO_INCREMENT';
            } elseif ($row['COLUMN_DEFAULT'] !== null) {
                $rendered .= ' DEFAULT ' . (is_numeric($row['COLUMN_DEFAULT'])
                    ? (string) $row['COLUMN_DEFAULT']
                    : "'" . str_replace("'", "''", (string) $row['COLUMN_DEFAULT']) . "'");
            }
            $out[$row['COLUMN_NAME']] = ['rendered' => $rendered] + $row;
        }
        return $out;
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->value(
            'SELECT COUNT(*) FROM ' . $this->connection->platform()->quote($table)
        );
    }
}
