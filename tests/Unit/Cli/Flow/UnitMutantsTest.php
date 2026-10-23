<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\UnitMutants;
use NightWorksIO\MutationGate\Config\Uncovered as UncoveredSetting;
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
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Tally;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$mutant = static fn(int $line, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'LessThan', sprintf('%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
    Mutation::of('LessThan', MutatorFamily::Boundary, ''),
    $status,
    Unmeasured::duration(),
);

// Its newest proof holds one kill of a ledger, one killed, one survivor,
// one uncovered and one its runner's marker ignored.
$newest = static fn(): NewestProofs => NewestProofs::in(Proofs::of(Proof::held(
    Digest::of('key'),
    Path::of('src/Money.php'),
    Mutants::of(
        $mutant(2, MutantStatus::Killed),
        $mutant(3, MutantStatus::Survived),
        $mutant(4, MutantStatus::Uncovered),
        $mutant(5, MutantStatus::IgnoredByMarker),
    ),
    ProvedKills::of(ProvedKill::of(MutantId::hash(Path::of('src/Money.php'), 'LessThan', '1', 0), Path::of('src/Money.php'), Line::of(1), 'LessThan', TestIds::none())),
    Run::of('main', Moment::at('2026-10-01T10:00:00Z'), Digest::of(str_repeat('b', 64))),
)));

$project = static function (): string {
    $project = Flows::project();
    Scratch::write($project, 'src/Sum.php', "<?php\n\nfunction sum(int \$a, int \$b): int\n{\n    return \$a + \$b + 1;\n}\n");
    Scratch::write($project, 'src/Broken.php', "<?php\n\nfunction (\n");

    return $project;
};

it('estimates a unit by its newest proof, scored as a verdict scores it', function () use ($newest, $project): void {
    $candidate = UnitMutants::of(Flows::adapters($project()), Flows::settings(), $newest())->candidate(Unit::file(Path::of('src/Money.php')));
    $excluding = UnitMutants::of(Flows::adapters($project()), Flows::settings(UncoveredSetting::excluded()), $newest())
        ->candidate(Unit::file(Path::of('src/Money.php')));

    expect($candidate instanceof Candidate ? [$candidate->mutants(), $candidate->last()] : $candidate)->toEqual([4, Tally::of(2, 4)])
        ->and($excluding instanceof Candidate ? [$excluding->mutants(), $excluding->last()] : $excluding)->toEqual([3, Tally::of(2, 3)]);
});

it('estimates a file no proof records by the mutants the engine makes of it', function () use ($newest, $project): void {
    $estimated = UnitMutants::of(Flows::adapters($project(), [], Engine::with(new PlusToMinus())), Flows::settings(), $newest());
    $sum = $estimated->candidate(Unit::file(Path::of('src/Sum.php')));

    expect($sum instanceof Candidate ? [$sum->mutants(), $sum->last()] : $sum)->toEqual([2, NotGiven::value()]);
});

it('estimates nothing of a held unit no proof records, of a file the engine cannot read or parse, or with no engine', function () use ($newest, $project): void {
    $engine = UnitMutants::of(Flows::adapters($project(), [], Engine::with(new PlusToMinus())), Flows::settings(), $newest());
    $none = UnitMutants::of(Flows::adapters($project()), Flows::settings(), $newest());

    expect($engine->candidate(Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php'))))->toEqual(NotGiven::value())
        ->and($engine->candidate(Unit::file(Path::of('src/Gone.php'))))->toEqual(NotGiven::value())
        ->and($engine->candidate(Unit::file(Path::of('src/Broken.php'))))->toEqual(NotGiven::value())
        ->and($none->candidate(Unit::file(Path::of('src/Sum.php'))))->toEqual(NotGiven::value());
});
