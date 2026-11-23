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

    /** The run of the mutant's unit crashed before it judged the mutant, which a later wave judges (ADR-0330). */
    case Crashed = 'crashed';
    /** The mutant's run, of it alone, crashed, and the unmutated control that judges that has not run (ADR-0333). */
    case CrashedAlone = 'crashed-alone';

    private const string SAID = 'The time budget ran out before %s. A larger --budget judges it.';

    private const string CRASHED = 'Its run crashed before it judged this mutant. Another run judges it.';
    private const string CRASHED_ALONE
        = 'Its run, of this mutant alone, crashed, and its tests have not run unmutated yet. Another run judges it.';

    /** Why the mutant is unjudged, and what judges it. */
    public function reason(): Reason
    {
        return Reason::ranOutOf($this, $this->said());
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
            self::BeforeMutating, self::BeforeRunning, self::Crashed, self::CrashedAlone => true,
            self::BeforeConfirming, self::BeforeChecking, self::BeforeControlling, self::BeforeVouching => false,
        };
    }

    /** What its reason says. */
    private function said(): string
    {
        return match ($this) {
            self::Crashed => self::CRASHED,
            self::CrashedAlone => self::CRASHED_ALONE,
            self::BeforeMutating => sprintf(self::SAID, 'this run mutated its unit'),
            self::BeforeRunning => sprintf(self::SAID, 'its own run finished'),
            self::BeforeConfirming => sprintf(self::SAID, 'its survival could be confirmed'),
            self::BeforeChecking => sprintf(self::SAID, 'static analysis could check it'),
            self::BeforeControlling => sprintf(self::SAID, 'its tests could run unmutated to judge it'),
            self::BeforeVouching => sprintf(self::SAID, 'its kill could be vouched for'),
        };
    }
}
