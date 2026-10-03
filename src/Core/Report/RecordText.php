<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Proof\Recorded;

use function sprintf;

/**
 * One record of a mutant on one line: its status, the run whose proof holds
 * it, that ledger's scope and when the run was, as `survived, by github:7/1
 * on main at 2026-09-29T10:00:00Z`.
 */
final readonly class RecordText
{
    public static function of(Recorded $recorded): string
    {
        return sprintf(
            '%s, by %s on %s at %s',
            $recorded->mutant()->status()->value,
            $recorded->proof()->run()->id(),
            $recorded->scope()->name(),
            $recorded->proof()->run()->at()->value(),
        );
    }
}
