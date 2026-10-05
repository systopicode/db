<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/**
 * One proposal out of the audit.
 *
 * It carries what it would do, what it would cost, and the statements that do
 * it. Nothing runs until the caller hands it back to `Audit::apply()` — so an
 * action is safe to pass around, show, sort and filter.
 *
 * `$lossless` and `$filled` are the two things a caller needs to make the
 * confirmation meaningful. "Drop column" is a different question depending on
 * whether 0 or 143 rows have something in it, and the audit knows the answer.
 */
final class AuditAction
{
    /**
     * @param list<string> $statements  the SQL that carries it out; empty for
     *                                  a migration hook, which runs as code
     * @param int|null     $filled      how many rows hold a value — only set
     *                                  where something would be lost
     */
    public function __construct(
        public readonly AuditKind $kind,
        public readonly string $subject,
        public readonly string $describe,
        public readonly array $statements,
        public readonly bool $lossless,
        public readonly ?int $filled = null,
        /**
         * Rows that contradict this action — only set where it was worth
         * asking, which today means a foreign key.
         */
        public readonly ?int $violations = null,
    ) {}

    /** Would this throw data away? */
    public bool $destructive {
        get => !$this->lossless && ($this->filled === null || $this->filled > 0);
    }

    /**
     * Will this fail as things stand?
     *
     * Different from destructive in the way that matters: a blocked action
     * loses nothing, it simply cannot run. Knowing that beforehand is the
     * difference between reading a list and finding out one error at a time.
     */
    public bool $blocked {
        get => $this->violations !== null && $this->violations > 0;
    }

    /** One line, the way `db audit` prints it. */
    public function line(): string
    {
        return sprintf('%-15s %-34s %s', $this->kind->value, $this->subject, $this->describe);
    }

    /**
     * A stable name for this action, to send over a form and find again.
     *
     * Deliberately derived from kind and subject rather than a position in the
     * list: the audit runs again between showing the list and applying it, so
     * an index would silently shift if anything changed in between. A name
     * that no longer matches is simply not found, and nothing runs — which is
     * the right answer to "the world moved while you were reading".
     */
    public function key(): string
    {
        return $this->kind->value . '|' . $this->subject;
    }
}
