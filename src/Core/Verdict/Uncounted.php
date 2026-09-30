<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;

use function sprintf;

/**
 * Why a unit a time budget ran out before cannot count by its newest result
 * (ADR-0008, decision 1): that result's mutant set is not the one the code on
 * disk makes, or nothing says whether it is. The unit fails the verdict,
 * named with the command that judges it.
 */
enum Uncounted
{
    /** No ledger the run reads holds a result of it. */
    case NoResult;

    /** Its newest result, or this run, records no digests of its inputs: a ledger or plan of an earlier format. */
    case NoDigests;

    /** Its source is not the source its newest result was established on. */
    case SourceChanged;

    /** What decides its mutant set besides its source changed: the gate, the config, the runner or its setup. */
    case MutationChanged;

    private const string SAID = <<<'SAID'
        %s is unjudged: the time budget ran out before this run mutated it.
        %s More time judges it: %s
        SAID;

    /** Why the unit at this path fails the verdict, and what judges it. */
    public function said(Path $unit): string
    {
        return sprintf(self::SAID, $unit->value(), $this->why(), OutOfTime::MORE_TIME);
    }

    private function why(): string
    {
        return match ($this) {
            self::NoResult => 'No ledger holds a result of it to count.',
            self::NoDigests => 'Its newest result records no digests of its inputs to say it is this code\'s.',
            self::SourceChanged => 'Its newest result is of other source, so its mutants are not this code\'s.',
            self::MutationChanged => 'Its newest result was made with another gate, config, runner or runner setup.',
        };
    }
}
