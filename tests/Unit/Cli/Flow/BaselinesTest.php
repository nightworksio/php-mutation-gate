<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
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
$floorsIn = static fn(string $project): Baselines => new Baselines(Flows::adapters($project), Path::of('floors.json'));

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
        ->toEqual(BaselineFile::decode('{"format": 9}', Path::of('floors.json')))
        ->and($floorsIn($project)->committed())->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge a baseline it cannot read', function (): void {
    $project = Flows::project();
    mkdir(sprintf('%s/floors.json', $project));

    expect(new Baselines(Flows::adapters($project), Path::of('floors.json'))->committed())
        ->toEqual(CannotJudge::because(sprintf('%s/floors.json could not be read.', $project)));
});

it('reads the baseline the default branch holds at its remote', function () use ($floors, $remotes): void {
    $changes = $remotes(...[
        'main' => BaselineFile::encode($floors(70.0)),
        'release/2' => BaselineFile::encode($floors(55.0)),
    ]);
    $baselines = new Baselines(Flows::adapters(Flows::project(), [], $changes), Path::of('floors.json'));

    expect($baselines->onDefaultBranch(Scope::branch('main')))->toEqual($floors(70.0))
        ->and($baselines->onDefaultBranch(Scope::branch('release/2')))->toEqual($floors(55.0));
});

it('reads no baseline where the default branch holds none it can read', function (ChangeSourceFake $changes): void {
    $baselines = new Baselines(Flows::adapters(Flows::project(), [], $changes), Path::of('floors.json'));

    expect($baselines->onDefaultBranch(Scope::branch('main')))->toEqual(Baseline::none());
})->with([
    'no file' => [$remotes()],
    'a file that is not a baseline' => [$remotes(main: 'not a baseline')],
]);

it('writes the baseline back into its file', function () use ($floors): void {
    $project = Flows::project();

    $written = new Baselines(Flows::adapters($project), Path::of('floors.json'))->write($floors(81.25));

    expect($written)->toEqual(Written::to(sprintf('%s/floors.json', $project)))
        ->and(file_get_contents(sprintf('%s/floors.json', $project)))->toBe(BaselineFile::encode($floors(81.25)));
});

it('cannot judge a baseline it cannot write', function () use ($floors): void {
    $project = Flows::project();
    mkdir(sprintf('%s/floors.json', $project));

    expect(new Baselines(Flows::adapters($project), Path::of('floors.json'))->write($floors(81.25)))
        ->toBeInstanceOf(CannotJudge::class);
});
