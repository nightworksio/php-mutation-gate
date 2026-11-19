<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Cost\MutantTimes;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Cost\MutatorTally;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Tallies;

$mutant = static fn(string $mutator, int $line, Seconds|Unmeasured $duration = new Unmeasured()): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), $mutator, sprintf('-%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
    Mutation::of($mutator, MutatorFamily::Arithmetic, sprintf('-%d', $line)),
    MutantStatus::Killed,
    $duration,
);

it('shares a shard\'s time among its mutants by their durations, and gives none to a mutant it did not run', function () use ($mutant): void {
    $times = MutantTimes::of(
        Mutants::of($mutant('Plus', 1, Seconds::of(1.0)), $mutant('Plus', 2, Seconds::of(3.0))),
        CoverageMap::of(),
        Seconds::of(8.0),
    );

    expect($times->took($mutant('Plus', 1)->id()))->toEqual(Seconds::of(2.0))
        ->and($times->took($mutant('Plus', 2)->id()))->toEqual(Seconds::of(6.0))
        ->and($times->took($mutant('Plus', 3)->id()))->toBeInstanceOf(NotGiven::class);
});

it('shares by durations that add up to less than a second, too', function () use ($mutant): void {
    $times = MutantTimes::of(
        Mutants::of($mutant('Plus', 1, Seconds::of(0.25)), $mutant('Plus', 2, Seconds::of(0.5))),
        CoverageMap::of(),
        Seconds::of(3.0),
    );

    expect($times->took($mutant('Plus', 1)->id()))->toEqual(Seconds::of(1.0))
        ->and($times->took($mutant('Plus', 2)->id()))->toEqual(Seconds::of(2.0));
});

it('keeps each runner\'s mutators in the order of their names, whatever order it was told them in', function (): void {
    $tallies = Tallies::holding(Tallies::of('fake', 'Plus', 1, 1, 0, 0, 1.0), Tallies::of('fake', 'Minus', 1, 1, 0, 0, 1.0));

    expect(array_map(static fn(MutatorTally $tally): string => $tally->mutator(), [...$tallies]))->toBe(['Minus', 'Plus']);
});

it('keeps its runners in the order of their names, whatever order it was told them in', function (): void {
    $tallies = Tallies::holding(Tallies::of('pest', 'Plus', 1, 1, 0, 0, 1.0), Tallies::of('infection', 'Plus', 1, 1, 0, 0, 1.0));

    expect(array_map(static fn(MutatorTally $tally): string => $tally->runner(), [...$tallies]))->toBe(['infection', 'pest']);
});

it('gives a mutator its share of a single microsecond', function (): void {
    $tally = Tallies::of('fake', 'Plus', 1, 1, 0, 0, 0.000001);

    expect(Tallies::holding($tally)->shareOf($tally))->toEqual(Percentage::inHundredths(10_000));
});

it('joins the times of each shard\'s mutants, and times nothing where it was given none', function () use ($mutant): void {
    $first = MutantTimes::of(Mutants::of($mutant('Plus', 1, Seconds::of(1.0))), CoverageMap::of(), Seconds::of(2.0));
    $second = MutantTimes::of(Mutants::of($mutant('Plus', 2, Seconds::of(1.0))), CoverageMap::of(), Seconds::of(3.0));
    $joined = MutantTimes::none()->and($first)->and($second);

    expect([$joined->took($mutant('Plus', 1)->id()), $joined->took($mutant('Plus', 2)->id())])->toEqual([Seconds::of(2.0), Seconds::of(3.0)])
        ->and(MutantTimes::none()->took($mutant('Plus', 1)->id()))->toBeInstanceOf(NotGiven::class);
});

it('weighs a mutant with no duration by the time the tests covering its line took, and shares equally where nothing was timed', function () use ($mutant): void {
    $coverage = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(1), TestId::of('MoneyTest::adds'))
        ->timed(TestId::of('MoneyTest::adds'), Seconds::of(3.0))
        ->covered(Path::of('src/Money.php'), Line::of(2), TestId::of('MoneyTest::subtracts'))
        ->timed(TestId::of('MoneyTest::subtracts'), Seconds::of(1.0));
    $covered = MutantTimes::of(Mutants::of($mutant('Plus', 1), $mutant('Plus', 2)), $coverage, Seconds::of(4.0));
    $untimed = MutantTimes::of(Mutants::of($mutant('Plus', 1), $mutant('Plus', 2)), CoverageMap::of(), Seconds::of(4.0));

    expect($covered->took($mutant('Plus', 1)->id()))->toEqual(Seconds::of(3.0))
        ->and($covered->took($mutant('Plus', 2)->id()))->toEqual(Seconds::of(1.0))
        ->and($untimed->took($mutant('Plus', 1)->id()))->toEqual(Seconds::of(2.0));
});

it('tallies the mutants a shard ran, each once, by its runner and mutator, as the verdict judged it', function () use ($mutant): void {
    $plus = $mutant('Plus', 1, Seconds::of(1.0));
    $minus = $mutant('Minus', 2, Seconds::of(1.0));
    $carried = $mutant('Plus', 3, Seconds::of(1.0));
    $judged = JudgedMutants::of(
        JudgedMutant::of($plus, MutantJudgement::Killed),
        JudgedMutant::of($minus, MutantJudgement::Survived),
        JudgedMutant::of($plus, MutantJudgement::Killed),
        JudgedMutant::of($carried, MutantJudgement::Survived),
    );
    $tallies = MutatorTallies::none()->after(
        Name::of('pest'),
        $judged,
        MutantTimes::of(Mutants::of($plus, $minus), CoverageMap::of(), Seconds::of(10.0)),
    );

    expect($tallies->of(Name::of('pest'), RunnerMutatorName::of('Plus')))->toEqual(MutatorTally::of('pest', 'Plus')->after(MutantJudgement::Killed, Seconds::of(5.0)))
        ->and($tallies->of(Name::of('pest'), RunnerMutatorName::of('Minus')))->toEqual(MutatorTally::of('pest', 'Minus')->after(MutantJudgement::Survived, Seconds::of(5.0)))
        ->and($tallies->of(Name::of('infection'), RunnerMutatorName::of('Plus')))->toEqual(MutatorTally::of('infection', 'Plus'))
        ->and($tallies->seconds())->toEqual(Seconds::of(10.0));
});

it('adds what a later shard ran to what it tallied', function () use ($mutant): void {
    $first = $mutant('Plus', 1, Seconds::of(1.0));
    $second = $mutant('Plus', 2, Seconds::of(1.0));
    $judged = JudgedMutants::of(JudgedMutant::of($first, MutantJudgement::Killed), JudgedMutant::of($second, MutantJudgement::Killed));
    $tallies = MutatorTallies::none()
        ->after(Name::of('pest'), $judged, MutantTimes::of(Mutants::of($first), CoverageMap::of(), Seconds::of(2.0)))
        ->after(Name::of('pest'), $judged, MutantTimes::of(Mutants::of($second), CoverageMap::of(), Seconds::of(3.0)));

    expect([$tallies->of(Name::of('pest'), RunnerMutatorName::of('Plus'))->killed(), $tallies->of(Name::of('pest'), RunnerMutatorName::of('Plus'))->seconds()])->toEqual([2, Seconds::of(5.0)]);
});

it('reads its own tally of a mutator before another ledger\'s, and the other\'s where it holds none', function (): void {
    $mine = MutatorTallies::none()->with(Tallies::of('pest', 'Plus', 1, 1, 0, 0, 1.0));
    $theirs = MutatorTallies::none()
        ->with(Tallies::of('pest', 'Plus', 9, 9, 0, 0, 9.0))
        ->with(Tallies::of('pest', 'Minus', 2, 0, 2, 0, 2.0));
    $read = $mine->and($theirs);

    expect($read->of(Name::of('pest'), RunnerMutatorName::of('Plus'))->mutants())->toBe(1)
        ->and($read->of(Name::of('pest'), RunnerMutatorName::of('Minus'))->mutants())->toBe(2);
});

it('ranks the costliest first, then by runner and mutator, and gives each its share of every mutator\'s time', function (): void {
    $tallies = MutatorTallies::none()
        ->with(Tallies::of('pest', 'Minus', 1, 1, 0, 0, 1.0))
        ->with(Tallies::of('pest', 'Plus', 1, 1, 0, 0, 3.0))
        ->with(Tallies::of('infection', 'Plus', 1, 1, 0, 0, 1.0));
    $ranked = array_map(static fn(MutatorTally $tally): string => sprintf('%s %s', $tally->runner(), $tally->mutator()), [...$tallies->costliest()]);

    expect($ranked)->toBe(['pest Plus', 'infection Plus', 'pest Minus'])
        ->and($tallies->shareOf($tallies->of(Name::of('pest'), RunnerMutatorName::of('Plus'))))->toEqual(Percentage::parse(60))
        ->and(MutatorTallies::none()->shareOf(MutatorTally::of('pest', 'Plus')))->toBeInstanceOf(NotGiven::class);
});

it('keeps only what it tallied of one runner\'s mutators', function (): void {
    $tallies = Tallies::holding(Tallies::of('pest', 'Plus', 1, 1, 0, 0, 1.0), Tallies::of('infection', 'Plus', 2, 2, 0, 0, 2.0));

    expect($tallies->under(Name::of('pest')))->toEqual(Tallies::holding(Tallies::of('pest', 'Plus', 1, 1, 0, 0, 1.0)))
        ->and($tallies->under(Name::of('fake')))->toEqual(MutatorTallies::none());
});

it('adds up a mutant\'s times from every shard that judged it by a part of the test files', function () use ($mutant): void {
    $first = MutantTimes::of(Mutants::of($mutant('Plus', 1, Seconds::of(1.0))), CoverageMap::of(), Seconds::of(2.0));
    $second = MutantTimes::of(Mutants::of($mutant('Plus', 1, Seconds::of(1.0))), CoverageMap::of(), Seconds::of(3.0));

    expect($first->and($second)->took($mutant('Plus', 1)->id()))->toEqual(Seconds::of(5.0));
});
