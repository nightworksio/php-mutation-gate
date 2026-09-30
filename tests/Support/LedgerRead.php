<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;

/** What a store's read gave, for a test to look at. */
final class LedgerRead
{
    /** The ledger a store read; a test fails where the store could not read one. */
    public static function ledger(Ledger|Unreadable $read): Ledger
    {
        if ($read instanceof Unreadable) {
            throw new LogicException($read->why());
        }

        return $read;
    }

    /**
     * Why a store could not read a ledger, and what it says; nothing where it read one.
     *
     * @return list<UnreadReason|string>
     */
    public static function unread(Ledger|Unreadable $read): array
    {
        return $read instanceof Unreadable ? [$read->reason(), $read->why()] : [];
    }
}
