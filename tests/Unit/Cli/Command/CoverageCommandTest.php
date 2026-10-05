<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\CoverageCommand;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * `coverage` in a project, measured by this runner.
 */
$coverage = static fn(string $project, string $input, ScriptedRunner $runner): FlowCommands => FlowCommands::run(
    CoverageCommand::command(FlowCommands::composition($project, $runner, new ProofStoreFake(), Flows::ci())),
    $input,
);

/** The map the fake runner measures, at the commit the checkout is at, in a clean tree, with its test file's key. */
$measured = static fn(): array => [
    RunnerFake::ofTheFixture()->coverage(CoverageRead::from(Path::of('anywhere'))),
    MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false),
    ['tests/MoneyTest.php'],
];

/** What the map a project holds at a path measures, where, and which test files it keys. */
$kept = static function (string $file): array {
    $kept = CoverageMapFile::kept((string) file_get_contents($file), MapLimits::standard());

    return $kept instanceof KeptMap
        ? [$kept->map(), $kept->measuredAt(), array_keys($kept->keys()->written())]
        : [$kept];
};

it('writes the map the suite\'s coverage run measured into the directory it is told', function () use (
    $coverage,
    $measured,
    $kept,
): void {
    $project = FlowCommands::project();

    $written = $coverage($project, '--into=build/coverage', ScriptedRunner::fixture());

    expect($written->code)->toBe(0)
        ->and($written->output)->toBe(sprintf("Wrote %s/build/coverage/map.json.gz.\n", $project))
        ->and($written->errors)->toBe("Coverage: there is no kept map, so every test was measured.\n")
        ->and($kept(sprintf('%s/build/coverage/map.json.gz', $project)))->toEqual($measured());
});

it('measures the suite withholding every CI\'s tokens from its tests', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    FlowCommands::run(CoverageCommand::command(
        FlowCommands::composition(FlowCommands::project(), $runner, new ProofStoreFake(), Flows::ci()),
    ));
    $withheld = $runner->ran()[0]->withheld();

    expect($runner->asked())->toHaveCount(1)
        ->and(preg_match($withheld->pattern(), 'CI_JOB_TOKEN'))->toBe(1)
        ->and(preg_match($withheld->pattern(), 'GITHUB_TOKEN'))->toBe(1);
});

it('writes the map into the workspace where it is told no directory', function () use ($coverage): void {
    $project = FlowCommands::project();

    $written = $coverage($project, '', ScriptedRunner::fixture());

    expect($written->output)->toBe(sprintf("Wrote %s/.mutation-gate/coverage/map.json.gz.\n", $project))
        ->and(is_file(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)))->toBeTrue();
});

it('cannot write a map where the directory cannot hold it', function () use ($coverage): void {
    $project = FlowCommands::project();
    mkdir(sprintf('%s/build/map.json.gz', $project), recursive: true);

    $written = $coverage($project, '--into=build', ScriptedRunner::fixture());

    expect($written->code)->toBe(2)
        ->and($written->output)->toBe('')
        ->and($written->errors)->toBe(sprintf("%s/build/map.json.gz could not be written.\n", $project));
});

it('says why the suite\'s coverage could not be measured, and writes no map', function () use ($coverage): void {
    $project = FlowCommands::project();

    $written = $coverage($project, '', ScriptedRunner::fixture()->uncovering('The suite failed.'));

    expect($written->code)->toBe(2)
        ->and($written->output)->toBe('')
        ->and($written->errors)->toBe("The suite failed.\n")
        ->and(file_exists(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)))->toBeFalse();
});

it('cannot measure with a config it cannot read', function () use ($coverage): void {
    $written = $coverage(FlowCommands::project('"shards": {"max": 0}'), '', ScriptedRunner::fixture());

    expect($written->code)->toBe(2)
        ->and($written->errors)->toContain('shards.max:');
});

it('offers the directory to write the map into', function (): void {
    $project = FlowCommands::project();
    $command = CoverageCommand::command(
        FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()),
    );

    expect($command->getDefinition()->getOption('into')->isValueRequired())->toBeTrue();
});

it('measures again only the test files whose entries moved against the map the default branch keeps', function (): void {
    $project = FlowCommands::project();
    $store = new ProofStoreFake();
    $money = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'));
    $first = new CoverageAsked(ScriptedRunner::fixture(), $money);
    FlowCommands::run(CoverageCommand::command(FlowCommands::composition($project, $first, $store, Flows::ci())));
    $store->keep(
        Scope::branch('main'),
        Companion::Coverage,
        Contents::of((string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project))),
    );
    $again = new CoverageAsked(ScriptedRunner::fixture(), $money);

    $written = FlowCommands::run(CoverageCommand::command(FlowCommands::composition($project, $again, $store, Flows::ci())));

    expect($written->errors)->toBe("Coverage: measured 0 of 1 test files again; kept the rest from the default branch's map.\n")
        ->and($again->ran())->toBe([])
        ->and($again->asked())->toEqual([CoverageRead::from(Path::of('.mutation-gate/coverage'))]);
});

it('measures every test, keying none, where the project cannot be listed', function () use ($coverage): void {
    $project = FlowCommands::project();

    $written = $coverage($project, '', ScriptedRunner::fixture()->unlisted('No group can be listed.'));
    $map = CoverageMapFile::kept(
        (string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)),
        MapLimits::standard(),
    );

    expect($written->code)->toBe(0)
        ->and($written->errors)->toBe("Coverage: No group can be listed. So every test was measured.\n")
        ->and($map instanceof KeptMap ? $map->keys()->written() : $map)->toBe([]);
});
