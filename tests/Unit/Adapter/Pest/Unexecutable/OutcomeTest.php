<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Outcome;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('is killed, survived, timed out, skipped, or unjudged with its reason, and unmeasured until it took some time', function (): void {
    expect(Outcome::killed()->status())->toBe(MutantStatus::Killed)
        ->and(Outcome::survived()->status())->toBe(MutantStatus::Survived)
        ->and(Outcome::timedOut()->status())->toBe(MutantStatus::TimedOut)
        ->and(Outcome::skipped()->status())->toBe(MutantStatus::Skipped)
        ->and(Outcome::skipped()->reason())->toEqual(Unreported::reason())
        ->and(Outcome::skipped()->duration())->toEqual(Unmeasured::duration())
        ->and(Outcome::skipped()->limit())->toEqual(Unmeasured::duration())
        ->and(Outcome::unjudged('never loaded')->status())->toBe(MutantStatus::Unjudged)
        ->and(Outcome::unjudged('never loaded')->reason())->toEqual(Reason::that('never loaded'))
        ->and(Outcome::killed()->reason())->toEqual(Unreported::reason())
        ->and(Outcome::killed()->duration())->toEqual(Unmeasured::duration())
        ->and(Outcome::killed()->took(Seconds::of(0.5))->duration())->toEqual(Seconds::of(0.5))
        ->and(Outcome::killed()->took(Seconds::of(0.5))->status())->toBe(MutantStatus::Killed);
});

it('says the limit its run was allowed, none until it is given one, whatever it took', function (): void {
    expect(Outcome::timedOut()->limit())->toEqual(Unmeasured::duration())
        ->and(Outcome::timedOut()->within(Seconds::of(6.0))->limit())->toEqual(Seconds::of(6.0))
        ->and(Outcome::timedOut()->within(Seconds::of(6.0))->took(Seconds::of(6.1))->limit())->toEqual(Seconds::of(6.0))
        ->and(Outcome::timedOut()->within(Seconds::of(6.0))->took(Seconds::of(6.1))->duration())->toEqual(Seconds::of(6.1));
});

it('leaves the mutant alive only where it survived', function (): void {
    expect(Outcome::survived()->leftAlive())->toBeTrue()
        ->and(Outcome::killed()->leftAlive())->toBeFalse()
        ->and(Outcome::timedOut()->leftAlive())->toBeFalse()
        ->and(Outcome::skipped()->leftAlive())->toBeFalse()
        ->and(Outcome::unjudged('x')->leftAlive())->toBeFalse();
});
