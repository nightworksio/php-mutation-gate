<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use RuntimeException;

use function sprintf;

/**
 * A file the gate wrote does not hold what it should where it should. Thrown
 * while a codec reads one entry, and answered at the codec's edge: a ledger
 * drops the entry, and a plan or a shard's result is refused.
 */
final class NotInShape extends RuntimeException
{
    public static function at(string $where, string $expected): self
    {
        return new self(sprintf('%s is not %s.', $where, $expected));
    }

    public static function missing(string $where): self
    {
        return new self(sprintf('%s is missing.', $where));
    }
}
