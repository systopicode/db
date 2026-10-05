<?php

declare(strict_types=1);

namespace Systopic\Db\Type;

use Systopic\Db\Schema\Column;
use Systopic\Db\Schema\Kind;

/**
 * Raw bytes — `VARBINARY` / `BLOB`.
 *
 * A PHP `string` is already a byte string, so nothing here is about storage —
 * it is about telling the schema that these bytes are **not text**. A
 * `varchar` would get a collation, would be compared case-insensitively and
 * would be mangled on a charset conversion.
 *
 * `log_requests.request_ip` is the case at hand: `varbinary(16)` holding the
 * result of `inet_pton()`, which is 4 bytes for IPv4 and 16 for IPv6. In a
 * `varchar` that would be broken data the first time somebody converted the
 * table to utf8mb4.
 *
 *     #[Col(length: 16)]
 *     public ?Binary $ip { set => … }
 */
final class Binary implements ColumnType
{
    public function __construct(public readonly string $bytes = '')
    {
    }

    /** From a human-readable IP address, as `inet_pton` stores it. */
    public static function fromIp(string $address): self
    {
        $packed = @inet_pton($address);
        return new self($packed === false ? '' : $packed);
    }

    /** Back to a readable address, when that is what the bytes are. */
    public function toIp(): ?string
    {
        if ($this->bytes === '') {
            return null;
        }
        $address = @inet_ntop($this->bytes);
        return $address === false ? null : $address;
    }

    public static function column(): Column
    {
        return new Column(Kind::Binary);
    }

    public static function fromDb(mixed $raw): static
    {
        return new static((string) ($raw ?? ''));
    }

    public function toDb(): mixed
    {
        return $this->bytes;
    }
}
