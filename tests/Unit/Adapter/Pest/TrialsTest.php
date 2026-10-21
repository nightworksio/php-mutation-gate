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
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot judge a run\'s trials where the shard was handed no whole map to find the tests that read a value in', function (): void {
    $at = Project::at(Scratch::directory(), Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $own = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('own'), Path::of('whole')));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $trials = new Trials($at, $shell, new CapDirectory(), Triage::standard()->bounds(), new Remembered());

    expect($trials->of(
        MutationResult::of(Mutants::none(), 0),
        $request,
        sprintf('%s/results.jsonl', Scratch::directory()),
        new HandedOver($own, $at),
        $own,
        Laps::from(static fn(): Seconds => Seconds::of(0.0)),
    ))
        ->toEqual(CoverageMapFile::missingAt($at->absolute(CoverageMapFile::in(Path::of('whole')))))
        ->and($shell->commands())->toBe([]);
});
