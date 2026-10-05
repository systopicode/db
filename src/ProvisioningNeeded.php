<?php

declare(strict_types=1);

namespace Systopic\Db;

use PDOException;
use RuntimeException;

/**
 * A statement failed inside an open transaction and would need provisioning.
 *
 * The provisioner does not act here, and that is the whole point: DDL commits
 * implicitly in MySQL, so creating a column in the middle of a transaction
 * would make everything written so far permanent — and the `rollBack()` that
 * follows would throw "There is no active transaction".
 *
 * The caller therefore decides. The pattern is always the same:
 *
 *     try {
 *         $connection->transactional($write);
 *     } catch (ProvisioningNeeded $e) {
 *         // the transaction is already rolled back by transactional()
 *         $provisioner->provide($e->cause);
 *         $connection->transactional($write);   // replay the whole commit
 *     }
 *
 * That is why `$write` has to be replayable — which a unit of work is by
 * construction: it holds objects, not statements.
 */
final class ProvisioningNeeded extends RuntimeException
{
    public function __construct(
        public readonly string $sql,
        public readonly PDOException $cause,
    ) {
        parent::__construct(
            'statement failed inside an open transaction and would need provisioning: '
            . $cause->getMessage()
            . ' — roll back, provision, replay the commit',
            0,
            $cause,
        );
    }
}
