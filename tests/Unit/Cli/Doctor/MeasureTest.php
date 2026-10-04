<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Doctor\Measure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Input\ArrayInput;

afterEach(function (): void {
    Scratch::sweep();
});

/** The most memory the suite's processes held, as the measuring flow reads it. */
$peak = MemoryCap::of(300, MemoryUnit::Megabytes);

/** What `--measure` adds to nothing observed, in a project whose suite this runner runs. */
$measuring = static fn(string $project, Runner $runner): Observations => new Measure(
    FlowCommands::composition($project, $runner, new ProofStoreFake(), Flows::ci(), new PeakMemoryFake($peak)),
)->into(Observations::none(), new ArrayInput([]));

it('runs the whole suite once under coverage, withholding every CI\'s tokens, writes no map, and reads its peak memory', function () use (
    $measuring,
    $peak,
): void {
    $project = FlowCommands::project();
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $measured = $measuring($project, $runner)->asked()->measurement();
    $withheld = $runner->ran()[0]->withheld();

    expect($measured)->toBeInstanceOf(Measurement::class)
        ->and($measured instanceof Measurement ? $measured->coverage() : null)->toEqual(Flows::map())
        ->and($measured instanceof Measurement ? count($measured->held()) : 0)->toBeGreaterThan(0)
        ->and($measured instanceof Measurement ? $measured->peak() : null)->toEqual($peak)
        ->and($runner->asked())->toHaveCount(1)
        ->and(preg_match($withheld->pattern(), 'CI_JOB_TOKEN'))->toBe(1)
        ->and(file_exists(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)))->toBeFalse();
});

it('measures why the suite could not be run, from the coverage run or from finding the units', function (
    Runner $runner,
    string $why,
) use ($measuring): void {
    $measured = $measuring(FlowCommands::project(), $runner)->asked()->measurement();

    expect($measured instanceof Measurement ? $measured->coverage() : null)->toEqual(CannotJudge::because($why));
})->with([
    'the coverage run' => [
        new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('1 test failed.')),
        '1 test failed.',
    ],
    'the groups' => [ScriptedRunner::fixture()->unlisted('Pest lists no groups.'), 'Pest lists no groups.'],
]);

it('measures nothing where the config is invalid, which its own check reports', function () use ($measuring): void {
    $project = FlowCommands::project('"shards": {"max": 0}');

    expect($measuring($project, ScriptedRunner::fixture())->asked()->measurement())->toEqual(NotGiven::value());
});

it('measures why a run could not be built from a valid config', function () use ($measuring): void {
    $project = FlowCommands::project('"extensions": ["Acme\\\\Nowhere"]');
    $measured = $measuring($project, ScriptedRunner::fixture())->asked()->measurement();

    expect($measured instanceof Measurement ? $measured->coverage() : null)->toBeInstanceOf(CannotJudge::class)
        ->and($measured instanceof Measurement ? count($measured->held()) : -1)->toBe(0);
});
