<?php

declare(strict_types=1);

namespace Systopic\Db\Schema;

/** One column, as the database has it and as the model declares it. */
final class StatusColumn
{
    public function __construct(
        public readonly string $name,
        /** As rendered from `information_schema`, or NULL when not there. */
        public readonly ?string $actual,
        /** As the platform renders the declaration, or NULL when not declared. */
        public readonly ?string $declared,
        public readonly StatusState $state,
    ) {}

    /** Worth showing a colour for? */
    public bool $differs {
        get => $this->state !== StatusState::Same && $this->state !== StatusState::Unknown;
    }
}
