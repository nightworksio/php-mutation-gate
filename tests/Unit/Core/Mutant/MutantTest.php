<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('is one mutant as its runner reported it', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);
    $location = Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42));
    $mutation = Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<=");
    $mutant = Mutant::of($id, '9a0b7e', $location, $mutation, MutantStatus::Survived, Seconds::of(0.4));

    expect($mutant->id())->toBe($id)
        ->and($mutant->nativeId())->toBe('9a0b7e')
        ->and($mutant->location())->toBe($location)
        ->and($mutant->mutation())->toBe($mutation)
        ->and($mutant->status())->toBe(MutantStatus::Survived)
        ->and($mutant->duration())->toEqual(Seconds::of(0.4))
        ->and($mutant->limit())->toEqual(Unmeasured::duration());
});

it('carries the seconds its runner allowed it, keeping the rest of its record', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);
    $location = Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42));
    $mutation = Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<=");
    $mutant = Mutant::of($id, '9a0b7e', $location, $mutation, MutantStatus::TimedOut, Seconds::of(0.4));
    $limited = $mutant->withLimit(Seconds::of(5.0));

    expect($limited->limit())->toEqual(Seconds::of(5.0))
        ->and($limited->id())->toBe($id)
        ->and($limited->nativeId())->toBe('9a0b7e')
        ->and($limited->location())->toBe($location)
        ->and($limited->mutation())->toBe($mutation)
        ->and($limited->status())->toBe(MutantStatus::TimedOut)
        ->and($limited->duration())->toEqual(Seconds::of(0.4))
        ->and($mutant->limit())->toEqual(Unmeasured::duration());
});

it('gives no reason until one is said', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);
    $location = Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42));
    $mutation = Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<=");
    $mutant = Mutant::of($id, '9a0b7e', $location, $mutation, MutantStatus::Unjudged, Seconds::of(0.4));
    $explained = $mutant->because(Reason::that('No test could be named.'));

    expect($mutant->reason())->toBeInstanceOf(Unreported::class)
        ->and($explained->reason())->toEqual(Reason::that('No test could be named.'))
        ->and($explained->id())->toBe($id)
        ->and($explained->nativeId())->toBe('9a0b7e')
        ->and($explained->location())->toBe($location)
        ->and($explained->mutation())->toBe($mutation)
        ->and($explained->status())->toBe(MutantStatus::Unjudged)
        ->and($explained->duration())->toEqual(Seconds::of(0.4));
});

it('is left unjudged by a budget that ran out, saying before what, keeping the rest of its record', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);
    $location = Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42));
    $mutation = Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<=");
    $mutant = Mutant::of($id, '9a0b7e', $location, $mutation, MutantStatus::Survived, Seconds::of(0.4));
    $unjudged = $mutant->unjudged(OutOfTime::BeforeConfirming);

    expect($unjudged->status())->toBe(MutantStatus::Unjudged)
        ->and($unjudged->reason())->toEqual(OutOfTime::BeforeConfirming->reason())
        ->and($unjudged->id())->toBe($id)
        ->and($unjudged->location())->toBe($location)
        ->and($unjudged->mutation())->toBe($mutation)
        ->and($unjudged->duration())->toEqual(Seconds::of(0.4))
        ->and($mutant->status())->toBe(MutantStatus::Survived);
});

it('names the tests that killed it, none until they are said, keeping the rest of its record', function (): void {
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0),
        '12',
        Location::of(Path::of('src/Money.php'), Line::of(4), Unreported::line()),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-"),
        MutantStatus::Killed,
        Seconds::of(0.1),
    );
    $killed = $mutant->killedBy(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('CartTest::totals')));

    expect($mutant->killers())->toEqual(TestIds::none())
        ->and($killed->killers())->toEqual(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('CartTest::totals')))
        ->and($killed->id())->toEqual($mutant->id())
        ->and($killed->status())->toBe(MutantStatus::Killed)
        ->and($killed->duration())->toEqual(Seconds::of(0.1));
});

it('is killed by the static analyser that rejected it, none until one did, keeping the rest of its record', function (): void {
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0),
        '12',
        Location::of(Path::of('src/Money.php'), Line::of(4), Unreported::line()),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-"),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $rejection = Rejection::by('mago', Finding::error('invalid-return-statement', 'Money::add() must return int.'));
    $rejected = $mutant->rejected($rejection);

    expect($mutant->rejection())->toEqual(Unreported::rejection())
        ->and($rejected->rejection())->toBe($rejection)
        ->and($rejected->status())->toBe(MutantStatus::KilledByStaticAnalysis)
        ->and($rejected->id())->toEqual($mutant->id())
        ->and($rejected->duration())->toEqual(Seconds::of(0.1))
        ->and($mutant->status())->toBe(MutantStatus::Survived);
});

it('keeps no rejection once a budget leaves it unjudged, and no killer once an analyser rejects it', function (): void {
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0),
        '12',
        Location::of(Path::of('src/Money.php'), Line::of(4), Unreported::line()),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-"),
        MutantStatus::Killed,
        Seconds::of(0.1),
    )->killedBy(TestIds::of(TestId::of('MoneyTest::adds')));
    $rejected = $mutant->rejected(Rejection::by('phpstan', Finding::error('return.type', 'No.')));

    expect($rejected->killers())->toEqual(TestIds::none())
        ->and($rejected->unjudged(OutOfTime::BeforeMutating)->rejection())->toEqual(Unreported::rejection())
        ->and($rejected->unjudged(OutOfTime::BeforeMutating)->status())->toBe(MutantStatus::Unjudged);
});
