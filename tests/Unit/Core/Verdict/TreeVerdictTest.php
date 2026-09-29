<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

it('is a tree, its score and whether that met its floor', function (): void {
    $tree = Tree::at(Path::of('app/Http'), Floor::of(80), Package::at(Path::root()));
    $verdict = TreeVerdict::of($tree, Score::ofHundredths(7_950), Judgement::Failed);

    expect($verdict->tree())->toBe($tree)
        ->and($verdict->score())->toEqual(Score::ofHundredths(7_950))
        ->and($verdict->judgement())->toBe(Judgement::Failed);
});
