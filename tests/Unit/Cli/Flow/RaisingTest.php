<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Cli\Flow\Raising;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Two trees judged against a baseline: `src/Money.php`, which scores 50, held
 * to this floor, and `src/Held.php`, which scores 0.
 */
$judged = static function (Floor|Undeclared $money, Baseline $baseline): TreeVerdicts {
    $root = Package::at(Path::root());
    $trees = Trees::of(
        Tree::at(Path::of('src/Money.php'), $money, $root),
        Tree::at(Path::of('src/Held.php'), Floor::of(0.5), $root),
    );
    $fake = RunnerFake::ofTheFixture();
    $results = UnitResults::none();

    foreach (['src/Money.php', 'src/Held.php'] as $file) {
        $mutants = $fake->mutate(MutationRequest::of(Paths::of(Path::of($file)), WholeSuite::tests()))->mutants();
        $results = $results->with(UnitResult::of(Unit::file(Path::of($file)), Origin::Run, $mutants));
    }

    return Judge::of($trees, $baseline, Reach::nothing(Packages::of($trees)), Uncovered::Count)->trees($results);
};

$baselines = static fn(string $project): Baselines => new Baselines(Flows::adapters($project), Path::of('floors.json'));

it('writes every floor a score raised into the baseline, and says which lines to commit', function () use (
    $judged,
    $baselines,
): void {
    $project = Flows::project();
    $committed = Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(42.5)));

    $said = new Raising($baselines($project))->raise($committed, $judged(Floor::of(30), $committed));

    expect($said)->toBe(['Raised the floors in floors.json. Commit it:', '  src/Money.php: 50, was 42.5'])
        ->and(file_get_contents(sprintf('%s/floors.json', $project)))
        ->toBe(BaselineFile::encode(Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(50)))));
});

it('writes a missing floor at its measured score', function () use ($judged, $baselines): void {
    $project = Flows::project();

    $said = new Raising($baselines($project))->raise(Baseline::none(), $judged(Undeclared::floor(), Baseline::none()));

    expect($said)->toBe(['Raised the floors in floors.json. Commit it:', '  src/Money.php: 50, was none'])
        ->and(file_get_contents(sprintf('%s/floors.json', $project)))
        ->toBe(BaselineFile::encode(Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(50)))));
});

it('writes nothing where no floor rose', function () use ($judged, $baselines): void {
    $project = Flows::project();

    $said = new Raising($baselines($project))->raise(Baseline::none(), $judged(Floor::of(50), Baseline::none()));

    expect($said)->toBe(['No floor in floors.json rose.'])
        ->and(is_file(sprintf('%s/floors.json', $project)))->toBeFalse();
});

it('cannot judge where the baseline cannot be written', function () use ($judged, $baselines): void {
    $project = Flows::project();
    mkdir(sprintf('%s/floors.json', $project));

    expect(new Raising($baselines($project))->raise(Baseline::none(), $judged(Floor::of(30), Baseline::none())))
        ->toBeInstanceOf(CannotJudge::class);
});
