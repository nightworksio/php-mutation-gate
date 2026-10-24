<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Verdict\BaselineState;
use NightWorksIO\MutationGate\Core\Verdict\HeldSets;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Secured;

/** A verdict of one tree, held to this floor, and of these security sets, each judging one killed mutant. */
function baselineVerdict(Floor|Undeclared $tree, SecurityVerdict ...$security): Verdict
{
    $killed = Secured::mutant(MutantJudgement::Killed);
    $survivor = Secured::mutant(MutantJudgement::Survived, 2);

    return Verdict::of(TreeVerdicts::of(Secured::tree('src', Package::at(Path::root()), $tree, $killed, $survivor)))
        ->withSets(HeldSets::of(NewCodeVerdicts::none(), SecurityVerdicts::of(...$security)));
}

it('is current where every set is held to a floor its score does not rise above', function (): void {
    expect(BaselineState::of(baselineVerdict(Floor::of(50)), Path::root()))->toBe(BaselineState::Current)
        ->and(BaselineState::of(baselineVerdict(Floor::of(60)), Path::root()))->toBe(BaselineState::Current);
});

it('is stale where a tree\'s score, or a security set\'s, rose above the floor it was held to', function (): void {
    $risen = Secured::set('.', Floor::of(40), Floor::of(50), Secured::mutant(MutantJudgement::Killed));

    expect(BaselineState::of(baselineVerdict(Floor::of(40)), Path::root()))->toBe(BaselineState::Stale)
        ->and(BaselineState::of(baselineVerdict(Floor::of(50), $risen), Path::root()))->toBe(BaselineState::Stale);
});

it('is missing where a tree or a security set with a score is held to no floor, whatever else rose', function (): void {
    $unfloored = Secured::set('.', Undeclared::floor(), Unrecorded::floor(), Secured::mutant(MutantJudgement::Killed));

    expect(BaselineState::of(baselineVerdict(Undeclared::floor()), Path::root()))->toBe(BaselineState::Missing)
        ->and(BaselineState::of(baselineVerdict(Floor::of(40), $unfloored), Path::root()))->toBe(BaselineState::Missing);
});
