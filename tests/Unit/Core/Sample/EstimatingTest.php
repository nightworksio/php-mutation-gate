<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Census;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Estimate;
use NightWorksIO\MutationGate\Core\Sample\Estimating;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\Unsampled;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$src = static fn(): Tree => Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root()));
$trees = static fn(): Trees => Trees::of($src(), Tree::at(Path::of('app'), Floor::of(80), Package::at(Path::root())));
$file = static fn(string $path): Unit => Unit::file(Path::of($path));
$left = static fn(string $path, int $mutants): Candidate => Candidate::of(Unit::file(Path::of($path)), $mutants, NotGiven::value());

// src/A.php, sampled: 1 killed of 2. src/B.php, exact: 2 killed of 2, and one
// ignored, which no score counts. src/C.php, left out, estimated at 4.
$mutants = static fn(): JudgedMutants => JudgedMutants::of(
    Judged::mutant('1', MutantJudgement::Killed, 'src/A.php'),
    Judged::mutant('2', MutantJudgement::Survived, 'src/A.php'),
    Judged::mutant('3', MutantJudgement::Killed, 'src/B.php'),
    Judged::mutant('4', MutantJudgement::KilledByTimeout, 'src/B.php'),
    Judged::mutant('5', MutantJudgement::Ignored, 'src/B.php'),
);
$units = static fn(): JudgedUnits => JudgedUnits::of(
    JudgedUnit::of(Unit::file(Path::of('src/A.php')), Origin::Run),
    JudgedUnit::of(Unit::file(Path::of('src/B.php')), Origin::Run),
);

it('leaves a tree of a run that was not sampled a census', function () use ($trees, $src, $units, $mutants): void {
    expect(Estimating::of(Sample::none(), $trees(), Uncovered::Count)->tree($src(), $units(), $mutants()))
        ->toBeInstanceOf(Census::class);
});

it('estimates a tree from the units its sample ran, those it left out, and the rest counted exactly', function () use ($trees, $src, $file, $left, $units, $mutants): void {
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)->holding(
        Units::of($file('src/A.php'), $file('app/Z.php')),
        Unsampled::none()->with($left('src/C.php', 4))->with($left('app/Y.php', 9)),
    );
    $estimate = Estimating::of($sample, $trees(), Uncovered::Count)->tree($src(), $units(), $mutants());
    $score = $estimate instanceof Estimate ? $estimate->score() : null;

    // (2 + 1/2 × (2 + 4)) / (2 + 6) = 62.5%.
    expect($estimate)->toBeInstanceOf(Estimate::class)
        ->and($estimate instanceof Estimate ? [$estimate->sampledUnits(), $estimate->exactUnits(), $estimate->totalUnits()] : [])
        ->toBe([1, 1, 3])
        ->and($score instanceof Score ? $score->hundredths() : 0)->toBe(6_250)
        ->and($estimate instanceof Estimate ? $estimate->sample() : null)->toBe($sample);
});

it('tallies a held unit the sample ran over every file its path holds', function () use ($trees, $src, $left, $units, $mutants): void {
    $held = Unit::held(Path::of('src'), Group::named('money'));
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)->holding(
        Units::of($held),
        Unsampled::none()->with($left('src/C.php', 4)),
    );
    $estimate = Estimating::of($sample, $trees(), Uncovered::Count)->tree($src(), $units(), $mutants());
    $score = $estimate instanceof Estimate ? $estimate->score() : null;

    // The held unit holds every counted mutant, 3 killed of 4, and C's 4 are left out.
    expect($score instanceof Score ? $score->hundredths() : 0)->toBe(7_500)
        ->and($estimate instanceof Estimate ? $estimate->sampledUnits() : 0)->toBe(1);
});

it('tallies a sampled file with no judged mutant as holding none', function () use ($trees, $src, $file, $left, $units, $mutants): void {
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)->holding(
        Units::of($file('src/Empty.php')),
        Unsampled::none()->with($left('src/C.php', 4)),
    );
    $estimate = Estimating::of($sample, $trees(), Uncovered::Count)->tree($src(), $units(), $mutants());

    expect($estimate instanceof Estimate ? [$estimate->sampledUnits(), $estimate->lower(), $estimate->upper()] : [])
        ->toBe([1, 3_750, 8_750]);
});

it('tallies every unit of the tree its sample ran', function () use ($trees, $src, $file, $left, $units, $mutants): void {
    $sample = Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0)->holding(
        Units::of($file('src/A.php'), $file('src/B.php')),
        Unsampled::none()->with($left('src/C.php', 4)),
    );
    $estimate = Estimating::of($sample, $trees(), Uncovered::Count)->tree($src(), $units(), $mutants());
    $score = $estimate instanceof Estimate ? $estimate->score() : null;

    expect($estimate instanceof Estimate ? [$estimate->sampledUnits(), $estimate->exactUnits()] : [])->toBe([2, 0])
        ->and($score instanceof Score ? $score->hundredths() : 0)->toBe(7_500);
});
