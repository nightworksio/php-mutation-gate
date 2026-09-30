<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Ratchet;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Judged;

// Three killed of four: 75%.
$threeOfFour = Judged::mutants(
    MutantJudgement::Killed,
    MutantJudgement::Killed,
    MutantJudgement::Killed,
    MutantJudgement::Survived,
);

$judged = static fn(
    string $path,
    Floor|Exempt|Undeclared $declared,
    Floor|Unrecorded $baseline,
    JudgedMutants $mutants,
): TreeVerdict => TreeVerdict::judged(
    Tree::at(Path::of($path), $declared, Package::at(Path::root())),
    $baseline,
    JudgedUnits::none(),
    $mutants,
    Uncovered::Count,
);
$values = static fn(Paths $paths): array => array_map(
    static fn(Path $path): string => $path->value(),
    [...$paths],
);
$texts = static fn(Failures $failures): array => array_map(
    static fn(Failure $failure): string => $failure->text(),
    [...$failures],
);
$baseline = Path::of('mutation-gate.baseline.json');

it('names each tree with a score and no floor declared or in the baseline, in order', function () use (
    $judged,
    $values,
    $threeOfFour,
): void {
    $verdicts = TreeVerdicts::of(
        $judged('app/Http', Undeclared::floor(), Unrecorded::floor(), $threeOfFour),
        $judged('app/Domain', Floor::of(80), Unrecorded::floor(), $threeOfFour),
        $judged('app/Legacy', Undeclared::floor(), Floor::of(60), $threeOfFour),
        $judged('app/Empty', Undeclared::floor(), Unrecorded::floor(), JudgedMutants::none()),
        $judged('app/Generated', Exempt::because('Generated on every build'), Unrecorded::floor(), $threeOfFour),
        $judged('app/Jobs', Undeclared::floor(), Unrecorded::floor(), $threeOfFour),
    );

    expect($values(Ratchet::unfloored($verdicts)))->toBe(['app/Http', 'app/Jobs'])
        ->and(Ratchet::unfloored(TreeVerdicts::none()))->toHaveCount(0);
});

it('stops a run on each tree held to no floor, saying how to give it one', function () use ($texts, $baseline): void {
    $failures = Ratchet::unflooredBecause(Paths::of(Path::of('app/Http'), Path::of('app/Jobs')), $baseline);
    $said = static fn(string $tree): string => sprintf(
        "%s has no floor: no floor is declared for it, and the baseline holds none.\n%s",
        $tree,
        'A tree is never held to no floor. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.',
    );

    expect($texts($failures))->toBe([$said('app/Http'), $said('app/Jobs')])
        ->and(Ratchet::unflooredBecause(Paths::none(), $baseline))->toHaveCount(0);
});

it('fails each tree whose score rose above the floor it was held to, until the raise is committed', function () use (
    $judged,
    $texts,
    $threeOfFour,
    $baseline,
): void {
    $verdicts = TreeVerdicts::of(
        $judged('app/Http', Floor::of(50), Unrecorded::floor(), $threeOfFour),
        $judged('app/Domain', Floor::of(75), Unrecorded::floor(), $threeOfFour),
        $judged('app/Legacy', Floor::of(40), Floor::of(62.5), $threeOfFour),
        $judged('app/Jobs', Undeclared::floor(), Unrecorded::floor(), $threeOfFour),
        $judged('app/Generated', Exempt::because('Generated on every build'), Unrecorded::floor(), $threeOfFour),
        $judged('app/Empty', Floor::of(10), Unrecorded::floor(), JudgedMutants::none()),
    );

    $said = static fn(string $tree, string $floor): string => sprintf(
        "%s scored 75, above the floor of %s it was held to. Commit the raised floor with this change:\n%s",
        $tree,
        $floor,
        'run mutation-gate baseline --write and commit mutation-gate.baseline.json.',
    );

    expect($texts(Ratchet::required($verdicts, $baseline)))
        ->toBe([$said('app/Http', '50'), $said('app/Legacy', '62.5')])
        ->and(Ratchet::required(TreeVerdicts::none(), $baseline))->toHaveCount(0);
});
