<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

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
