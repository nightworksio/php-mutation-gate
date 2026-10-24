<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\VerdictProvenance;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;

it('names the gate, the commit judged, the runner and the baseline file', function (): void {
    $adapters = Flows::adapters(Flows::project());
    $provenance = VerdictProvenance::of($adapters, Flows::setup(), JudgingRuns::settings(), JudgedCommits::of('562bc979'));

    expect($provenance->gate())->toEqual(Flows::setup()->gate)
        ->and($provenance->commit())->toEqual(JudgedCommits::of('562bc979')->commit())
        ->and($provenance->runner())->toBeInstanceOf(Identity::class)
        ->and($provenance->runner())->toEqual(RunnerFake::ofTheFixture()->identity($adapters->withheld))
        ->and($provenance->baseline())->toEqual(JudgingRuns::settings()->floors()->baseline());
});

it('leaves out the commit git cannot tell, and a runner that cannot say what it is', function (): void {
    $runner = RunnerFake::ofTheFixture()->identifiedAs(CannotJudge::because('The runner printed no version.'));
    $provenance = VerdictProvenance::of(Flows::adapters(Flows::project(), [], $runner), Flows::setup(), JudgingRuns::settings(), CannotTell::because('git cannot read it'));

    expect($provenance->commit())->toBeInstanceOf(NotGiven::class)
        ->and($provenance->runner())->toBeInstanceOf(NotGiven::class)
        ->and($provenance->gate())->toEqual(Flows::setup()->gate);
});
