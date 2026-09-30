<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Report\ScoreChangeText;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$app = TreeVerdict::judged(
    Tree::at(Path::of('app'), Floor::of(50), Package::at(Path::root())),
    Unrecorded::floor(),
    JudgedUnits::none(),
    Judged::mutants(MutantJudgement::Killed, MutantJudgement::Survived),
    Uncovered::Count,
)->comparedWith(Score::ofHundredths(4_000));

it('says each reached tree\'s score against its floor and the base, and each not measured yet', function () use ($app): void {
    expect(ScoreChangeText::of(TreeVerdicts::of($app), Paths::of(Path::of('lib')), 0, 0))->toBe(
        "app scores 50.00% against its floor of 50.00%. That is +10.00 against the base.\n"
        . 'lib is not measured yet: a unit of it has no result.',
    );
});

it('counts the reached units with no local result and the files with unstaged changes', function (int $unjudged, int $unstaged, string $counted) use ($app): void {
    expect(ScoreChangeText::of(TreeVerdicts::of($app), Paths::none(), $unjudged, $unstaged))->toBe(sprintf(
        "app scores 50.00%% against its floor of 50.00%%. That is +10.00 against the base.\n%s",
        $counted,
    ));
})->with([
    'one of each' => [1, 1, "1 unit unjudged since your last run: mutation-gate\nThe scores include unstaged changes in 1 file."],
    'several of each' => [3, 2, "3 units unjudged since your last run: mutation-gate\nThe scores include unstaged changes in 2 files."],
    'unstaged files alone' => [0, 2, 'The scores include unstaged changes in 2 files.'],
]);

it('says when the change reaches no tree', function (): void {
    expect(ScoreChangeText::of(TreeVerdicts::none(), Paths::none(), 2, 0))
        ->toBe("The change reaches no tree.\n2 units unjudged since your last run: mutation-gate");
});

it('says why it shows no score without a coverage map', function (): void {
    expect(ScoreChangeText::unmapped())->toStartWith('No local run has left a coverage map');
});
