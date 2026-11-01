<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$floors = static fn(float $floor): Baseline => Baseline::of(Entry::of(Path::of('src'), Floor::of($floor)));

/** A checkout whose remote branches hold these baselines, each named by its branch. */
$remotes = static function (string ...$baselines): ChangeSourceFake {
    $files = [Revision::workingTree()->name() => Flows::FILES];

    foreach ($baselines as $branch => $text) {
        $files[sprintf('refs/remotes/origin/%s', $branch)] = ['floors.json' => $text];
    }

    return new ChangeSourceFake(Revision::ref('base'), Changes::none(), $files);
};

/** The baseline a project commits in `floors.json`. */
$floorsIn = static fn(string $project): Baselines => new Baselines(Flows::adapters($project), Path::of('floors.json'), GateCommand::installed());

it('names the file the baseline is committed in', function () use ($floorsIn): void {
    expect($floorsIn(Flows::project())->file())->toEqual(Path::of('floors.json'));
});

it('reads the baseline the checkout holds', function () use ($floors, $floorsIn): void {
    $project = Flows::project();
    Scratch::write($project, 'floors.json', BaselineFile::encode($floors(62.5)));

    expect($floorsIn($project)->committed())->toEqual($floors(62.5));
});

it('reads no baseline where there is no file yet', function () use ($floorsIn): void {
    expect($floorsIn(Flows::project())->committed())->toEqual(Baseline::none());
});

it('cannot judge a baseline that is not one', function () use ($floorsIn): void {
    $project = Flows::project();
    Scratch::write($project, 'floors.json', '{"format": 9}');

    expect($floorsIn($project)->committed())
        ->toEqual(BaselineFile::decode('{"format": 9}', Path::of('floors.json'), GateCommand::installed()))
        ->and($floorsIn($project)->committed())->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge a baseline it cannot read', function (): void {
    $project = Flows::project();
    mkdir(sprintf('%s/floors.json', $project));

    expect(new Baselines(Flows::adapters($project), Path::of('floors.json'), GateCommand::installed())->committed())
        ->toEqual(CannotJudge::because(sprintf('%s/floors.json could not be read.', $project)));
});

it('reads the baseline the default branch holds at its remote', function () use ($floors, $remotes): void {
    $changes = $remotes(...[
        'main' => BaselineFile::encode($floors(70.0)),
        'release/2' => BaselineFile::encode($floors(55.0)),
    ]);
    $baselines = new Baselines(Flows::adapters(Flows::project(), [], $changes), Path::of('floors.json'), GateCommand::installed());

    expect($baselines->onDefaultBranch(Scope::branch('main'), Flows::setup()))->toEqual($floors(70.0))
        ->and($baselines->onDefaultBranch(Scope::branch('release/2'), Flows::setup()))->toEqual($floors(55.0));
});

it('reads the baseline the default branch holds in the file its own config file names, where a change moved it', function (string $config, Baseline|CannotJudge $read) use ($floors): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runner": "pest", "baseline": {"path": "moved.json"}}');
    $effective = new Effective(
        $project,
        Registered::config(Extensions::of(Origin::of('nightworksio/mutation-gate')), static fn(): bool => true),
        new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
        new DateTimeImmutable(Configs::NOW),
    );
    $deciding = DecidingConfig::read($effective, CommandLine::nothing(), $project, Path::of('mutation-gate.json'));
    $base = Flows::setup();
    $setup = new Setup(Path::of('mutation-gate.json'), $base->gate, $base->installed, $base->clock, $base->memory, $deciding, $base->command);
    $remote = ['floors.json' => BaselineFile::encode($floors(70.0)), 'moved.json' => BaselineFile::encode($floors(10.0))];
    $files = [Revision::workingTree()->name() => Flows::FILES];
    $files = $config === 'unfetched'
        ? $files
        : [...$files, 'refs/remotes/origin/main' => $config === '' ? $remote : [...$remote, 'mutation-gate.json' => $config]];
    $changes = new ChangeSourceFake(Revision::ref('base'), Changes::none(), $files);
    $baselines = new Baselines(Flows::adapters($project, [], $changes), Path::of('moved.json'), GateCommand::installed());

    expect($baselines->onDefaultBranch(Scope::branch('main'), $setup))->toEqual($read);
})->with([
    'named there' => ['{"runner": "pest", "baseline": {"path": "floors.json"}}', fn(): Baseline => Baseline::of(Entry::of(Path::of('src'), Floor::of(70.0)))],
    'the standard file, where the default branch holds no config file' => ['', fn(): Baseline => Baseline::none()],
    'a config file it cannot read' => ['{"runner": 5}', fn(): CannotJudge => CannotJudge::because(<<<'SAID'
        The baseline refs/remotes/origin/main holds cannot be read,
        so a floor lowered here cannot be checked against it. mutation-gate.json on the default branch cannot be read, so the file it keeps its baseline in is not known. runner: expected a name, a class, or an object with use and with, got 5
        Fetch the default branch into the checkout before the verdict.
        SAID)],
    'a default branch git cannot read' => ['unfetched', fn(): CannotJudge => CannotJudge::because(<<<'SAID'
        The baseline refs/remotes/origin/main holds cannot be read,
        so a floor lowered here cannot be checked against it. refs/remotes/origin/main is not a revision this repository has.
        Fetch the default branch into the checkout before the verdict.
        SAID)],
]);

it('reads no baseline where the default branch holds none', function () use ($remotes): void {
    $baselines = new Baselines(Flows::adapters(Flows::project(), [], $remotes(main: '')), Path::of('elsewhere.json'), GateCommand::installed());

    expect($baselines->onDefaultBranch(Scope::branch('main'), Flows::setup()))->toEqual(Baseline::none());
});

it('cannot judge where git cannot read the default branch, or its file is not a baseline', function (
    ChangeSourceFake $changes,
    string $why,
): void {
    $baselines = new Baselines(Flows::adapters(Flows::project(), [], $changes), Path::of('floors.json'), GateCommand::installed());

    expect($baselines->onDefaultBranch(Scope::branch('main'), Flows::setup()))->toEqual(CannotJudge::because(sprintf(<<<'SAID'
        The baseline refs/remotes/origin/main holds cannot be read,
        so a floor lowered here cannot be checked against it. %s
        Fetch the default branch into the checkout before the verdict.
        SAID, $why)));
})->with([
    'a shallow checkout' => [fn(): ChangeSourceFake => $remotes(), 'refs/remotes/origin/main is not a revision this repository has.'],
    'a file that is not a baseline' => [
        fn(): ChangeSourceFake => $remotes(main: 'not a baseline'),
        fn(): string => sprintf(
            '%s %s',
            'The baseline floors.json cannot be read: the file.format is missing.',
            'Fix it, or run vendor/bin/mutation-gate baseline --write.',
        ),
    ],
]);

it('writes the baseline back into its file', function () use ($floors): void {
    $project = Flows::project();

    $written = new Baselines(Flows::adapters($project), Path::of('floors.json'), GateCommand::installed())->write($floors(81.25));

    expect($written)->toEqual(Written::to(sprintf('%s/floors.json', $project)))
        ->and(file_get_contents(sprintf('%s/floors.json', $project)))->toBe(BaselineFile::encode($floors(81.25)));
});

it('cannot judge a baseline it cannot write', function () use ($floors): void {
    $project = Flows::project();
    mkdir(sprintf('%s/floors.json', $project));

    expect(new Baselines(Flows::adapters($project), Path::of('floors.json'), GateCommand::installed())->write($floors(81.25)))
        ->toBeInstanceOf(CannotJudge::class);
});
