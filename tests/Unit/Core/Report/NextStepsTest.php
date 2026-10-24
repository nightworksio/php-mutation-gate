<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\NextSteps;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Verdict\HeldSets;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Secured;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('says how many more mutants each set below its floor must catch to reach it', function (): void {
    $tree = Secured::tree('src', Package::at(Path::root()), Floor::of(80), Secured::mutant(MutantJudgement::Killed), Secured::mutant(MutantJudgement::Survived, 2), Secured::mutant(MutantJudgement::Uncovered, 3));
    $security = Secured::set('.', Floor::of(100), Unrecorded::floor(), Secured::mutant(MutantJudgement::Survived, 4));
    $verdict = Verdict::of(TreeVerdicts::of($tree))->withSets(HeldSets::of(NewCodeVerdicts::none(), SecurityVerdicts::of($security)));

    expect(NextSteps::of($verdict))->toBe([
        'Catch 2 more of the mutants in src to bring its score up to its floor of 80.00%: each escaped or untested one says what test would catch it.',
        'Catch 1 more of the mutants in Security to bring its score up to its floor of 100.00%: each escaped or untested one says what test would catch it.',
    ]);
});

it('says to fix what keeps the gate from judging first, and each failure no floor decides last', function (): void {
    expect(NextSteps::of(Verdicts::named('cannot judge'))[0])->toBe('Fix what keeps the gate from judging, said above, then run it again.')
        ->and(NextSteps::of(Verdicts::failing()))->toBe([
            'Catch 4 more of the mutants in src to bring its score up to its floor of 80.00%: each escaped or untested one says what test would catch it.',
            'Catch 1 more of the mutants in New code to bring its score up to its floor of 100.00%: each escaped or untested one says what test would catch it.',
            'Fix each failure said above: no floor decides it.',
        ]);
});

it('says a run that passes needs nothing, and says nothing of a set that reaches its floor', function (): void {
    expect(NextSteps::of(Verdicts::passing()))->toBe([NextSteps::NOTHING])
        ->and(NextSteps::of(Verdicts::empty()))->toBe([NextSteps::NOTHING]);
});
