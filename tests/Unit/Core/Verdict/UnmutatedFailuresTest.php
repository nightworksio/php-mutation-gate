<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Verdict\FailingTests;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\UnmutatedFailures;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/**
 * Each set of failing tests, as its line and the ids of the mutants it leaves out.
 *
 * @return list<array{string, list<string>}>
 */
function failingSets(UnmutatedFailures $failing): array
{
    return array_map(static fn(FailingTests $tests): array => [
        $tests->line(),
        array_map(static fn(JudgedMutant $mutant): string => $mutant->mutant()->id()->value(), [...$tests]),
    ], [...$failing]);
}

it('names the tests that fail without any mutant on a line of their own', function (): void {
    expect(FailingTests::of(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('TaxTest::rates')))->line())
        ->toBe('The tests fail without any mutant: MoneyTest::adds, TaxTest::rates');
});

it('groups the mutants whose tests fail without them by the tests that fail, each mutant once, and no other mutant', function (): void {
    $adds = Verdicts::failingUnmutated('11');
    $again = Verdicts::failingUnmutated('12');
    $rates = Verdicts::failingUnmutated('13', 'TaxTest::rates');
    $failing = UnmutatedFailures::in(JudgedMutants::of($adds, $adds, Verdicts::survivor(), $again, $rates));

    expect(failingSets($failing))->toBe([
        ['The tests fail without any mutant: MoneyTest::adds', [$adds->mutant()->id()->value(), $again->mutant()->id()->value()]],
        ['The tests fail without any mutant: TaxTest::rates', [$rates->mutant()->id()->value()]],
    ])
        ->and(count($failing))->toBe(2)
        ->and(count(UnmutatedFailures::in(JudgedMutants::of(Verdicts::survivor()))))->toBe(0)
        ->and(count(UnmutatedFailures::none()))->toBe(0);
});

it('cannot judge a verdict with tests that fail without any mutant, saying so on its own line after what else kept it from judging', function (): void {
    $failing = Verdicts::failingUnmutated();
    $verdict = Verdicts::of(Floor::of(50), $failing)->withCannotJudge(CannotJudge::because('The map is gone.'));

    expect($verdict->judgement())->toBe(Judgement::CannotJudge)
        ->and(array_map(static fn(CannotJudge $why): string => $why->why(), [...$verdict->obstacles()]))
        ->toBe(['The map is gone.', 'The tests fail without any mutant: MoneyTest::adds'])
        ->and(array_map(static fn(CannotJudge $why): string => $why->why(), [...$verdict->obstacles()->met()]))->toBe(['The map is gone.'])
        ->and(count($verdict->obstacles()))->toBe(2)
        ->and(count($verdict->obstacles()->met()))->toBe(1)
        ->and(failingSets($verdict->obstacles()->unmutatedFailures()))
        ->toBe([['The tests fail without any mutant: MoneyTest::adds', [$failing->mutant()->id()->value()]]])
        ->and(Verdicts::of(Floor::of(50), $failing)->judgement())->toBe(Judgement::CannotJudge);
});

it('leaves a mutant whose tests fail without it out of every score', function (): void {
    $verdict = Verdicts::of(Floor::of(100), JudgedMutant::of(Verdicts::killed(), MutantJudgement::Killed), Verdicts::failingUnmutated());
    [$tree] = [...$verdict->trees()];

    expect($tree->score())->toEqual(Score::of(1, 1))
        ->and($tree->counts()->number(MutantJudgement::FailsUnmutated))->toBe(1)
        ->and($tree->judgement())->toBe(Judgement::Passed)
        ->and($verdict->judgement())->toBe(Judgement::CannotJudge);
});
