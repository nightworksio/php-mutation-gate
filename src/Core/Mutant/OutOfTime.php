<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function sprintf;

/**
 * What a time budget ran out before, leaving a mutant unjudged (ADR-0008,
 * decision 1), each saying so with the command that judges it.
 */
enum OutOfTime
{
    /** The run never mutated the mutant's unit, which counts by its newest result in the ledgers. */
    case BeforeMutating;

    /** The mutant's time ran out at the cap, and its second run, with the cap doubled, did not fit. */
    case BeforeRetrying;

    /** The mutant survived, and its second run, which would confirm it, did not fit. */
    case BeforeConfirming;

    /** What judges a mutant a budget left unjudged. */
    public const string MORE_TIME = 'vendor/bin/mutation-gate run --budget=<duration>';

    private const string SAID = 'The time budget ran out before %s. More time judges it: %s';

    /** Why the mutant is unjudged, and what judges it. */
    public function reason(): Reason
    {
        return Reason::that(sprintf(self::SAID, $this->before(), self::MORE_TIME));
    }

    /** What the budget ran out before. */
    private function before(): string
    {
        return match ($this) {
            self::BeforeMutating => 'this run mutated its unit',
            self::BeforeRetrying => 'it could run again with a doubled limit',
            self::BeforeConfirming => 'its survival could be confirmed',
        };
    }
}
