<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

use Systopic\Db\Connection;

/**
 * Plays a mysqldump back into the connected database.
 *
 * The backup panel strips the `CONSTRAINT` lines from the dump's `CREATE
 * TABLE`s; the foreign keys still standing in the database would make its
 * `DROP TABLE`s fail, so they go first. Afterwards the database has no
 * foreign keys at all — `db audit` in the CMS proposes them again from the
 * Model classes.
 */
final class Restore
{
    public function __construct(private readonly Connection $connection) {}

    /** @return int how many foreign keys were dropped */
    public function dropForeignKeys(): int
    {
        $q = $this->connection->platform()->quote(...);
        $keys = $this->connection->all(
            'SELECT DISTINCT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL',
        );
        foreach ($keys as $key) {
            $this->connection->exec('ALTER TABLE ' . $q($key['TABLE_NAME']) . ' DROP FOREIGN KEY ' . $q($key['CONSTRAINT_NAME']));
        }
        return count($keys);
    }

    /**
     * Run the statements of a dump one by one (split at `;` + newline, as
     * mysqldump writes them).
     *
     * @return list<string> the errors — the restore goes on past them
     */
    public function run(string $dump): array
    {
        $errors = [];
        foreach (explode(";\n", $dump) as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            try {
                $this->connection->exec($statement);
                // A connection in silent error mode reports here instead.
                $info = $this->connection->pdo()->errorInfo();
                if (($info[0] ?? '00000') !== '00000') {
                    $errors[] = (string) ($info[2] ?? $info[0]);
                }
            } catch (\PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $errors;
    }
}
