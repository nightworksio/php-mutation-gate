<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\HandedOver;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Adapter\Pest\Trials;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot judge a run\'s trials where the shard was handed no whole map to find the tests that read a value in', function (): void {
    $at = Project::at(Scratch::directory(), Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $own = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('own'), Path::of('whole')));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $trials = new Trials($at, $shell, new CapDirectory(), Triage::standard()->limits()->bounds(), new Remembered());

    expect($trials->of(
        MutationResult::of(Mutants::none(), 0),
        $request,
        sprintf('%s/results.jsonl', Scratch::directory()),
        new HandedOver($own, $at),
        $own,
        Laps::from(static fn(): Seconds => Seconds::of(0.0)),
        static fn(): Unlimited => Unlimited::time(),
    ))
        ->toEqual(CoverageMapFile::missingAt($at->absolute(CoverageMapFile::in(Path::of('whole')))))
        ->and($shell->commands())->toBe([]);
});

it('reads no map and starts no trial once the run\'s time is out, leaving each mutant Pest left uncovered unjudged for a later run', function (): void {
    $at = Project::at(Scratch::directory(), Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $own = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('own'), Path::of('whole')));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $mutant = static fn(string $native, MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), $native, $native, 0),
        $native,
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('Increment', MutatorFamily::Literal, $native),
        $status,
        Seconds::of(0.1),
    );
    $uncovered = $mutant('a', MutantStatus::Uncovered);
    $killed = $mutant('b', MutantStatus::Killed);

    $tried = new Trials($at, $shell, new CapDirectory(), Triage::standard()->limits()->bounds(), new Remembered())->of(
        MutationResult::of(Mutants::of($uncovered, $killed), 0),
        $request,
        sprintf('%s/results.jsonl', Scratch::directory()),
        new HandedOver($own, $at),
        $own,
        Laps::from(static fn(): Seconds => Seconds::of(0.0)),
        static fn(): Seconds => Seconds::of(0.0),
    );

    expect($tried instanceof MutationResult ? [...$tried->mutants()] : $tried)
        ->toEqual([$uncovered->unjudged(OutOfTime::BeforeRunning), $killed])
        ->and($shell->commands())->toBe([]);
});
