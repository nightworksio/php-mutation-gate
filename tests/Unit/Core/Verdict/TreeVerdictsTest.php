<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$verdict = static fn(string $path): TreeVerdict => TreeVerdict::judged(
    Tree::at(Path::of($path), Undeclared::floor(), Package::at(Path::root())),
    Unrecorded::floor(),
    JudgedUnits::none(),
    JudgedMutants::none(),
    Uncovered::Count,
);
$paths = static fn(TreeVerdicts $verdicts): array => array_map(static fn(TreeVerdict $verdict): string => $verdict->tree()->path()->value(), iterator_to_array($verdicts, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(TreeVerdicts::none())->toHaveCount(0);
});

it('keeps verdicts in the order they were judged, numbered from nought', function () use ($verdict, $paths): void {
    expect($paths(TreeVerdicts::of(...['b' => $verdict('b'), 'a' => $verdict('a')])))->toBe(['b', 'a']);
});

it('adds a verdict without changing the verdicts it came from', function () use ($verdict, $paths): void {
    $verdicts = TreeVerdicts::of($verdict('a'));

    expect($paths($verdicts->with($verdict('b'))))->toBe(['a', 'b'])
        ->and($verdicts)->toHaveCount(1);
});

it('lists every unit and every mutant, tree by tree', function (): void {
    $tree = static fn(string $path, JudgedUnits $units, JudgedMutants $mutants): TreeVerdict => TreeVerdict::judged(
        Tree::at(Path::of($path), Undeclared::floor(), Package::at(Path::root())),
        Unrecorded::floor(),
        $units,
        $mutants,
        Uncovered::Count,
    );
    $unit = static fn(string $path): JudgedUnit => JudgedUnit::of(Unit::file(Path::of($path)), Origin::Run);
    $verdicts = TreeVerdicts::of(
        $tree('app', JudgedUnits::of($unit('app/A.php'), $unit('app/B.php')), Judged::mutants(MutantJudgement::Killed)),
        $tree('src', JudgedUnits::of($unit('src/C.php')), Judged::mutants(MutantJudgement::Survived, MutantJudgement::Flaky)),
    );
    $paths = array_map(
        static fn(JudgedUnit $judged): string => $judged->unit()->path()->value(),
        iterator_to_array($verdicts->units(), preserve_keys: true),
    );

    expect($paths)->toBe(['app/A.php', 'app/B.php', 'src/C.php'])
        ->and($verdicts->mutants()->counts()->number(MutantJudgement::Killed))->toBe(1)
        ->and($verdicts->mutants()->counts()->number(MutantJudgement::Flaky))->toBe(1)
        ->and($verdicts->mutants())->toHaveCount(3);
});
