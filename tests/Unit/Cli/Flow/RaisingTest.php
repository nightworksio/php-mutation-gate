<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Cli\Flow\Raising;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Secured;

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
    $results = UnitResults::none();

    foreach (['src/Money.php', 'src/Held.php'] as $file) {
        $results = $results->with(UnitResult::of(Unit::file(Path::of($file)), Origin::Run, Flows::mutantsOf($file)));
    }

    return Judge::of($trees, $baseline, Reach::nothing(Packages::of($trees)), Uncovered::Count, TimeoutMode::Confirm, Ignoring::none())
        ->trees($results);
};

$baselines = static fn(string $project): Baselines => new Baselines(Flows::adapters($project), Path::of('floors.json'));

it('writes every floor a score raised into the baseline, and says which lines to commit', function () use (
    $judged,
    $baselines,
): void {
    $project = Flows::project();
    $committed = Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(42.5)));

    $said = new Raising($baselines($project))->raise($committed, $judged(Floor::of(30), $committed), SecurityVerdicts::none());

    expect($said)->toBe(['Raised the floors in floors.json. Commit it:', '  src/Money.php: 50, was 42.5'])
        ->and(file_get_contents(sprintf('%s/floors.json', $project)))
        ->toBe(BaselineFile::encode(Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(50)))));
});

it('writes a missing floor at its measured score', function () use ($judged, $baselines): void {
    $project = Flows::project();

    $said = new Raising($baselines($project))->raise(Baseline::none(), $judged(Undeclared::floor(), Baseline::none()), SecurityVerdicts::none());

    expect($said)->toBe(['Raised the floors in floors.json. Commit it:', '  src/Money.php: 50, was none'])
        ->and(file_get_contents(sprintf('%s/floors.json', $project)))
        ->toBe(BaselineFile::encode(Baseline::of(Entry::of(Path::of('src/Money.php'), Floor::of(50)))));
});

it('writes nothing where no floor rose', function () use ($judged, $baselines): void {
    $project = Flows::project();

    $said = new Raising($baselines($project))->raise(Baseline::none(), $judged(Floor::of(50), Baseline::none()), SecurityVerdicts::none());

    expect($said)->toBe(['No floor in floors.json rose.'])
        ->and(is_file(sprintf('%s/floors.json', $project)))->toBeFalse();
});

it('cannot judge where the baseline cannot be written', function () use ($judged, $baselines): void {
    $project = Flows::project();
    mkdir(sprintf('%s/floors.json', $project));

    expect(new Raising($baselines($project))->raise(Baseline::none(), $judged(Floor::of(30), Baseline::none()), SecurityVerdicts::none()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('writes every security floor a set\'s score raised, each named by its package', function () use ($judged, $baselines): void {
    $project = Flows::project();
    $committed = Baseline::none()->withSecurity(Entry::of(Path::root(), Floor::of(60)));
    $security = SecurityVerdicts::of(
        Secured::set('.', Undeclared::floor(), Floor::of(60), Secured::mutant(MutantJudgement::Killed)),
        Secured::set('packages/billing', Undeclared::floor(), Unrecorded::floor(), Secured::mutant(MutantJudgement::Killed)),
    );

    $said = new Raising($baselines($project))->raise($committed, $judged(Floor::of(50), $committed), $security);

    expect($said)->toBe([
        'Raised the floors in floors.json. Commit it:',
        '  security set of .: 100, was 60',
        '  security set of packages/billing: 100, was none',
    ])
        ->and(file_get_contents(sprintf('%s/floors.json', $project)))->toBe(BaselineFile::encode(
            Baseline::none()->withSecurity(Entry::of(Path::root(), Floor::of(100)), Entry::of(Path::of('packages/billing'), Floor::of(100))),
        ));
});
