<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;

use function rtrim;
use function sprintf;

/** How a store reads an object it keeps beside a ledger: within that object's limits, and why it could not. */
final readonly class CompanionRead
{
    /** Why a store could not read one, where and as the store says. */
    private const string UNREAD = 'The kept %s at %s could not be read: %s.';

    /** What a store reads and writes of an object at most, and how long a read over a network may take. */
    public static function limitsOf(Companion $companion): LedgerLimits
    {
        return match ($companion) {
            Companion::Coverage => MapLimits::standard()->reading(),
        };
    }

    /** Why a store could not read an object at a place, as the store says. */
    public static function unread(Companion $companion, string $at, string $why): CannotJudge
    {
        return CannotJudge::because(sprintf(self::UNREAD, $companion->named(), $at, rtrim($why, '.')));
    }
}
