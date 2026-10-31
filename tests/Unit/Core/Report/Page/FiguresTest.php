<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Page\Figures;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Secured;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('names each style attribute its bars write once, each fill and each floor\'s mark, in the order they are written', function (): void {
    expect(Figures::styles(JsonReport::of(Verdicts::secured())))
        ->toBe(['width: 44.44%', 'left: 80.00%', 'width: 0.00%', 'left: 0.00%', 'width: 100.00%', 'left: 100.00%', 'left: 50.00%']);
});

it('names only the fill of a bar held to no floor, and nothing for a set with no score', function (): void {
    $unfloored = Secured::tree('src', Package::at(Path::root()), Undeclared::floor(), Secured::mutant(MutantJudgement::Killed), Secured::mutant(MutantJudgement::Survived, 2));

    expect(Figures::styles(JsonReport::of(Verdict::of(TreeVerdicts::of($unfloored)))))->toBe(['width: 50.00%'])
        ->and(Figures::styles(JsonReport::of(Verdicts::empty())))->toBe([]);
});
