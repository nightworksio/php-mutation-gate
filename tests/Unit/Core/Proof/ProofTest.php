<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('is a unit\'s mutants under the key of everything its verdict read, with the run that proved it', function (): void {
    $mutants = Mutants::none();
    $run = Run::of('github:5813/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $proof = Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), $mutants, $run);

    expect($proof->key()->value())->toBe('9c1e')
        ->and($proof->unit()->value())->toBe('src/Money.php')
        ->and($proof->reported())->toBe($mutants)
        ->and($proof->kills())->toEqual(ProvedKills::none())
        ->and($proof->run())->toBe($run);
});

it('holds the mutants reported in full and the kills a ledger proved, and the ids of both', function (): void {
    $run = Run::of('github:5813/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $survivor = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b", 0),
        '12',
        Location::of(Path::of('src/Money.php'), Line::of(4), Unreported::line()),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a\n+b"),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $kill = ProvedKill::of(
        MutantId::hash(Path::of('src/Money.php'), 'Minus', "-c\n+d", 0),
        Path::of('src/Money.php'),
        Line::of(9),
        'Minus',
        TestIds::of(TestId::of('MoneyTest::adds')),
    );
    $proof = Proof::held(Digest::of('9c1e'), Path::of('src/Money.php'), Mutants::of($survivor), ProvedKills::of($kill), $run);

    expect([...$proof->reported()])->toBe([$survivor])
        ->and([...$proof->kills()])->toBe([$kill])
        ->and([...$proof->ids()])->toEqual([$survivor->id(), $kill->id()]);
});

it('names the mutants two runs of the same code disagree on, a kill a ledger proved counting as killed', function (): void {
    $run = Run::of('github:5813/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $of = static fn(string $diff, MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'LessThan', $diff, 0),
        $diff,
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('LessThan', MutatorFamily::Boundary, $diff),
        $status,
        Unmeasured::duration(),
    );
    $kill = static fn(string $diff): ProvedKill => ProvedKill::of(
        $of($diff, MutantStatus::Killed)->id(),
        Path::of('src/Money.php'),
        Line::of(1),
        'LessThan',
        TestIds::none(),
    );
    $proof = Proof::held(
        Digest::of('9c1e'),
        Path::of('src/Money.php'),
        Mutants::of($of("-a\n+b", MutantStatus::Killed), $of("-c\n+d", MutantStatus::Survived), $of("-e\n+f", MutantStatus::Killed)),
        ProvedKills::of($kill("-i\n+j"), $kill("-k\n+l")),
        $run,
    );
    $fresh = Mutants::of(
        $of("-a\n+b", MutantStatus::Killed),
        $of("-c\n+d", MutantStatus::Killed),
        $of("-g\n+h", MutantStatus::Killed),
        $of("-i\n+j", MutantStatus::Killed),
        $of("-k\n+l", MutantStatus::Survived),
    );
    $other = Proof::held(
        Digest::of('9c1e'),
        Path::of('src/Money.php'),
        Mutants::of($of("-a\n+b", MutantStatus::Killed), $of("-c\n+d", MutantStatus::Survived), $of("-e\n+f", MutantStatus::Killed), $of("-i\n+j", MutantStatus::Killed)),
        ProvedKills::of($kill("-k\n+l")),
        $run,
    );

    expect([...$proof->disagreeingWith($fresh)])->toEqual([
        $of("-c\n+d", MutantStatus::Killed)->id(),
        $of("-e\n+f", MutantStatus::Killed)->id(),
        $of("-k\n+l", MutantStatus::Killed)->id(),
        $of("-g\n+h", MutantStatus::Killed)->id(),
    ])
        ->and($proof->disagreeingWith($other))->toHaveCount(0)
        ->and($proof->disagreeingWith($proof))->toHaveCount(0)
        ->and([...$other->disagreeingWith(Mutants::none())])->toHaveCount(5);
});

it('records no digests of its inputs until it is given them', function (): void {
    $run = Run::of('github:5813/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $proof = Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), Mutants::none(), $run);
    $inputs = Inputs::of(Digest::of(str_repeat('1', 64)), Digest::of(str_repeat('2', 64)));

    expect($proof->inputs())->toEqual(Undigested::proof())
        ->and(Proof::held(Digest::of('9c1e'), Path::of('src/Money.php'), Mutants::none(), ProvedKills::none(), $run)->inputs())->toEqual(Undigested::proof())
        ->and($proof->withInputs($inputs)->inputs())->toBe($inputs)
        ->and($proof->withInputs($inputs)->key())->toBe($proof->key());
});

it('reads a kill by static analysis and a kill by a test as one answer, and a survivor as another', function (): void {
    $run = Run::of('github:5813/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $of = static fn(MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-a\n+b", 0),
        '1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('LessThan', MutatorFamily::Boundary, "-a\n+b"),
        $status,
        Unmeasured::duration(),
    );
    $proof = Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), Mutants::of($of(MutantStatus::KilledByStaticAnalysis)), $run);

    expect($proof->disagreeingWith(Mutants::of($of(MutantStatus::Killed))))->toHaveCount(0)
        ->and($proof->disagreeingWith(Mutants::of($of(MutantStatus::Survived))))->toHaveCount(1);
});
