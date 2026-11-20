<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function sprintf;

/**
 * What a time budget ran out before, leaving a mutant unjudged
 * (ADR-0008), each saying so with the command that judges it. A result
 * records it by its value.
 */
enum OutOfTime: string
{
    /** The run never mutated the mutant's unit, which counts by its newest result in the ledgers. */
    case BeforeMutating = 'before-mutating';

    /** The mutant survived, and its second run, which would confirm it, did not fit. */
    case BeforeConfirming = 'before-confirming';

    /** The run of the mutant's unit was stopped at its deadline before the mutant's own run finished. */
    case BeforeRunning = 'before-running';

    /** The mutant survived, and static analysis's check of it, which may kill it, did not fit. */
    case BeforeChecking = 'before-checking';

    /** The unmutated control of the mutant's tests, which would judge its timeout or its kill, did not fit. */
    case BeforeControlling = 'before-controlling';

    /** The mutant was killed, and the run that vouches for the kill, which would let it stand, did not fit. */
    case BeforeVouching = 'before-vouching';

    private const string SAID = 'The time budget ran out before %s. A larger --budget judges it.';

    /** Why the mutant is unjudged, and what judges it. */
    public function reason(): Reason
    {
        return Reason::ranOutOf($this, sprintf(self::SAID, $this->before()));
    }

    /** Whether a time budget left this mutant unjudged, as its reason records. */
    public static function left(Mutant|ProvedKill $mutant): bool
    {
        $reason = $mutant->reason();

        return $mutant->status() === MutantStatus::Unjudged
            && $reason instanceof Reason
            && $reason->outOfTime() instanceof self;
    }

    /** Whether the budget left the mutant before its own run told anything of it. */
    public function isUnrun(): bool
    {
        return match ($this) {
            self::BeforeMutating, self::BeforeRunning => true,
            self::BeforeConfirming, self::BeforeChecking, self::BeforeControlling, self::BeforeVouching => false,
        };
    }

    /** What the budget ran out before. */
    private function before(): string
    {
        return match ($this) {
            self::BeforeMutating => 'this run mutated its unit',
            self::BeforeRunning => 'its own run finished',
            self::BeforeConfirming => 'its survival could be confirmed',
            self::BeforeChecking => 'static analysis could check it',
            self::BeforeControlling => 'its tests could run unmutated to judge it',
            self::BeforeVouching => 'its kill could be vouched for',
        };
    }
}
