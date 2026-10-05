<?php

declare(strict_types=1);

namespace Systopic\Db;

use RuntimeException;

/**
 * A value does not fit the **declaration**.
 *
 * That is a different failure from the one the provisioner repairs, and the
 * difference is the whole reason this exception exists:
 *
 *   the value does not fit the **column**       -> 1406 / 1264, the
 *                                                  provisioner widens and the
 *                                                  statement runs again
 *   the value does not fit the **declaration**  -> this, and it travels up
 *
 * In the first case the database has fallen behind the model and catching up
 * is lossless and right. In the second the caller handed over something the
 * model never promised to hold, and widening would silently change what the
 * model says. So it throws — at the assignment, naming the property:
 *
 *     try {
 *         $page->label = $input;
 *         $uow->persist($page)->commit();
 *     } catch (ValueRejected $e) {
 *         message::error($e->getMessage());
 *     }
 */
final class ValueRejected extends RuntimeException
{
    public function __construct(
        public readonly string $class,
        public readonly string $property,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            '$%s in %s: %s',
            $property,
            self::nameOf($class),
            $reason,
        ));
    }

    /**
     * `Pages`, not `Model` — every row class is called `Model` and lives in
     * the folder of its table, so the folder is the part that says which one.
     */
    private static function nameOf(string $class): string
    {
        $parts = explode('\\', $class);
        $short = array_pop($parts);
        return $short === 'Model' && $parts !== [] ? end($parts) : $short;
    }
}
