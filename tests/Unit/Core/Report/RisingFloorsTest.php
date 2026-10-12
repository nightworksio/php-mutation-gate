<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\RisingFloors;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\HeldSets;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Secured;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('names each floor a score raises, trees first, then each security set by its package', function (): void {
    expect(RisingFloors::of(Verdicts::passing()))->toEqual([['src', Floor::of(100)]])
        ->and(RisingFloors::of(Verdicts::secured()))->toEqual([['security set of packages/billing', Floor::of(100)]])
        ->and(RisingFloors::of(Verdicts::empty()))->toBe([]);
});

it('names a tree and a package by its path as one plain line, with no control character', function (): void {
    $mutant = Judged::mutant('a', MutantJudgement::Killed, "src/\e[31mred\nx/Money.php");
    $tree = TreeVerdict::judged(
        Tree::at(Path::of("src/\e[31mred\nx"), Floor::of(50), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of($mutant),
        Uncovered::Count,
    );
    $secured = Verdict::of(TreeVerdicts::of())->withSets(HeldSets::of(
        NewCodeVerdicts::none(),
        SecurityVerdicts::of(Secured::set("packages/\e[2Jbilling", Floor::of(50), Unrecorded::floor(), Secured::mutant(MutantJudgement::Killed))),
    ));

    expect(RisingFloors::of(Verdict::of(TreeVerdicts::of($tree))))->toEqual([['src/[31mred x', Floor::of(100)]])
        ->and(RisingFloors::of($secured))->toEqual([['security set of packages/[2Jbilling', Floor::of(100)]]);
});

it('names every floor a score raises, each tree\'s and then each security set\'s', function (): void {
    $tree = static fn(string $path): TreeVerdict => TreeVerdict::judged(
        Tree::at(Path::of($path), Floor::of(50), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of(Judged::mutant('a', MutantJudgement::Killed, sprintf('%s/Money.php', $path))),
        Uncovered::Count,
    );
    $set = static fn(string $package): SecurityVerdict => Secured::set($package, Floor::of(50), Unrecorded::floor(), Secured::mutant(MutantJudgement::Killed));
    $verdict = Verdict::of(TreeVerdicts::of($tree('src'), $tree('lib')))->withSets(HeldSets::of(
        NewCodeVerdicts::none(),
        SecurityVerdicts::of($set('packages/billing'), $set('packages/shipping')),
    ));

    expect(RisingFloors::of($verdict))->toEqual([
        ['src', Floor::of(100)],
        ['lib', Floor::of(100)],
        ['security set of packages/billing', Floor::of(100)],
        ['security set of packages/shipping', Floor::of(100)],
    ]);
});
