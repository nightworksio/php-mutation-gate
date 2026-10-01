<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\ResultRule;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

it('reports each mutant counted as not killed under its rule, one too slow to judge as unjudged', function (MutantJudgement $judgement, ResultRule $rule): void {
    expect(ResultRule::of($judgement))->toBe($rule);
})->with([
    [MutantJudgement::Survived, ResultRule::Survived],
    [MutantJudgement::Uncovered, ResultRule::Uncovered],
    [MutantJudgement::Unjudged, ResultRule::Unjudged],
    [MutantJudgement::TooSlowToJudge, ResultRule::Unjudged],
    [MutantJudgement::TooHeavyToJudge, ResultRule::Unjudged],
    [MutantJudgement::Flaky, ResultRule::Flaky],
]);

it('numbers and describes the rules in the order SARIF lists them', function (): void {
    expect(array_map(static fn(ResultRule $rule): array => [$rule->index(), $rule->value, $rule->text()], ResultRule::cases()))->toBe([
        [0, 'survived', 'A mutant no test fails on.'],
        [1, 'uncovered', 'A mutant on a line no test runs.'],
        [2, 'unjudged', 'A mutant the run did not judge, or could not judge in its time or memory limit.'],
        [3, 'flaky', 'A mutant its tests killed on one run and not on another.'],
    ]);
});
