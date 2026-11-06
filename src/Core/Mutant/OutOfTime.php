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

    /** The mutant timed out, and the unmutated control of its tests, which would judge it, did not fit. */
    case BeforeControlling = 'before-controlling';

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
            self::BeforeConfirming, self::BeforeControlling => false,
        };
    }

    /** What the budget ran out before. */
    private function before(): string
    {
        return match ($this) {
            self::BeforeMutating => 'this run mutated its unit',
            self::BeforeRunning => 'its own run finished',
            self::BeforeConfirming => 'its survival could be confirmed',
            self::BeforeControlling => 'its tests could run unmutated to judge its timeout',
        };
    }
}
