<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Outcome;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A kill no test is named for, whose run exited 1. */
$killed = static fn(): Outcome => Outcome::killed(TestIds::none(), Ended::unprinted(1, signalled: false));

it('is killed, survived, timed out, skipped, or unjudged with its reason, and unmeasured until it took some time', function () use ($killed): void {
    expect($killed()->status())->toBe(MutantStatus::Killed)
        ->and(Outcome::survived()->status())->toBe(MutantStatus::Survived)
        ->and(Outcome::timedOut()->status())->toBe(MutantStatus::TimedOut)
        ->and(Outcome::skipped()->status())->toBe(MutantStatus::Skipped)
        ->and(Outcome::skipped()->reason())->toEqual(Unreported::reason())
        ->and(Outcome::skipped()->duration())->toEqual(Unmeasured::duration())
        ->and(Outcome::skipped()->limit())->toEqual(Unmeasured::duration())
        ->and(Outcome::unjudged('never loaded')->status())->toBe(MutantStatus::Unjudged)
        ->and(Outcome::unjudged('never loaded')->reason())->toEqual(Reason::that('never loaded'))
        ->and($killed()->reason())->toEqual(Unreported::reason())
        ->and($killed()->duration())->toEqual(Unmeasured::duration())
        ->and($killed()->took(Seconds::of(0.5))->duration())->toEqual(Seconds::of(0.5))
        ->and($killed()->took(Seconds::of(0.5))->status())->toBe(MutantStatus::Killed);
});

it('says the limit its run was allowed, none until it is given one, whatever it took', function (): void {
    expect(Outcome::timedOut()->limit())->toEqual(Unmeasured::duration())
        ->and(Outcome::timedOut()->within(Seconds::of(6.0))->limit())->toEqual(Seconds::of(6.0))
        ->and(Outcome::timedOut()->within(Seconds::of(6.0))->took(Seconds::of(6.1))->limit())->toEqual(Seconds::of(6.0))
        ->and(Outcome::timedOut()->within(Seconds::of(6.0))->took(Seconds::of(6.1))->duration())->toEqual(Seconds::of(6.1));
});

it('leaves the mutant alive only where it survived', function () use ($killed): void {
    expect(Outcome::survived()->leftAlive())->toBeTrue()
        ->and($killed()->leftAlive())->toBeFalse()
        ->and(Outcome::timedOut()->leftAlive())->toBeFalse()
        ->and(Outcome::skipped()->leftAlive())->toBeFalse()
        ->and(Outcome::unjudged('x')->leftAlive())->toBeFalse();
});

it('judges a kill as killed by the tests that killed it, with how its run ended only where none is named', function (): void {
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Rates.php'), 'IncrementInteger', '-7 +8', 0),
        '1',
        Location::of(Path::of('src/Rates.php'), Line::of(14), Line::of(14)),
        Mutation::of('IncrementInteger', MutatorFamily::Arithmetic, '-7 +8'),
        MutantStatus::Uncovered,
        Unmeasured::duration(),
    );
    $ended = Ended::unprinted(1, signalled: false);
    $named = Outcome::killed(TestIds::of(TestId::of('P\Tests\RatesSpec::__pest_evaluable_it_reads')), $ended);
    $unnamed = Outcome::killed(TestIds::none(), $ended);

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$named->judging($mutant)->killers()]))
        ->toBe(['P\Tests\RatesSpec::__pest_evaluable_it_reads'])
        ->and($named->judging($mutant)->status())->toBe(MutantStatus::Killed)
        ->and($named->evidence())->toEqual(Evidence::none())
        ->and($unnamed->evidence())->toEqual(Evidence::none()->withEnded($ended))
        ->and(Outcome::survived()->evidence())->toEqual(Evidence::none());
});
