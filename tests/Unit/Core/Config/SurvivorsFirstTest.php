<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** A mutant of the fixture judged this way, at this line. */
$judged = static fn(int $line, MutantJudgement $judgement): JudgedMutant => JudgedMutant::of(
    Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''),
    $judgement,
);

it('re-checks as many survivors as the pull request comment lists by default, and none at 0', function (): void {
    expect(SurvivorsFirst::standard()->most())->toBe(20)
        ->and(SurvivorsFirst::standard()->isOff())->toBeFalse()
        ->and(SurvivorsFirst::atMost(3)->most())->toBe(3)
        ->and(SurvivorsFirst::atMost(1)->isOff())->toBeFalse()
        ->and(SurvivorsFirst::atMost(0)->isOff())->toBeTrue();
});

it('takes the first survivors and uncovered mutants, in their order, never one unjudged, flaky or too slow', function () use ($judged): void {
    $survivors = Survivors::of(
        $judged(1, MutantJudgement::Unjudged),
        $judged(2, MutantJudgement::Survived),
        $judged(3, MutantJudgement::Flaky),
        $judged(4, MutantJudgement::Uncovered),
        $judged(5, MutantJudgement::TooSlowToJudge),
        $judged(6, MutantJudgement::Survived),
    );

    $lines = static fn(Survivors $taken): array => array_map(
        static fn(JudgedMutant $mutant): int => $mutant->mutant()->location()->start()->number(),
        [...$taken],
    );

    expect($lines(SurvivorsFirst::standard()->taken($survivors)))->toBe([2, 4, 6])
        ->and($lines(SurvivorsFirst::atMost(2)->taken($survivors)))->toBe([2, 4])
        ->and($lines(SurvivorsFirst::atMost(0)->taken($survivors)))->toBe([]);
});

it('re-checks within twenty minutes across at most four jobs by default, and within and across what is set', function (): void {
    $set = SurvivorsFirst::of(budget: Seconds::minutes(45), shards: 6);

    expect(SurvivorsFirst::standard()->budget())->toEqual(Seconds::minutes(20))
        ->and(SurvivorsFirst::standard()->shards())->toBe(4)
        ->and($set->budget())->toEqual(Seconds::minutes(45))
        ->and($set->shards())->toBe(6)
        ->and($set->most())->toBe(20);
});

it('lays each key a later layer sets over an earlier one\'s, and keeps the earlier one\'s where the later sets none', function (): void {
    $earlier = SurvivorsFirst::of(5, Seconds::minutes(45), 6);
    $later = SurvivorsFirst::of(shards: 2);
    $laid = $earlier->over($later);

    expect([$laid->most(), $laid->budget(), $laid->shards()])->toEqual([5, Seconds::minutes(45), 2])
        ->and(SurvivorsFirst::standard()->over($earlier))->toEqual($earlier);
});

it('writes each key set, and with every key resolved writes the values they take', function (): void {
    expect(SurvivorsFirst::of(5, Seconds::minutes(45), 6)->written()->line())->toBe('{"max":5,"budget":"45m","shards":6}')
        ->and(SurvivorsFirst::standard()->resolved()->written()->line())->toBe('{"max":20,"budget":"20m","shards":4}')
        ->and(SurvivorsFirst::standard()->written()->isEmpty())->toBeTrue();
});

it('says the builder\'s call for each key set, and none for a key left out', function (): void {
    expect(SurvivorsFirst::of(5, Seconds::minutes(45), 6)->calls()->code())
        ->toBe(PhpCalls::inWith('Survivors::firstAtMost(5)', "Survivors::firstWithin('45m')", 'Survivors::firstAcross(6)')->code())
        ->and(SurvivorsFirst::standard()->calls())->toEqual(PhpCalls::none());
});
