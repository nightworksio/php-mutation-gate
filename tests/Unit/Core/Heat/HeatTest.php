<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Heat\Heat;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('drops by as much as its score fell since the base, and by nothing where it rose, held or is unscored', function (): void {
    $judged = static fn(MutantJudgement $judgement): JudgedMutants => JudgedMutants::of(JudgedMutant::of(
        Verdicts::mutant('src/Money.php:6', 'Plus', MutatorFamily::Arithmetic, '-6'),
        $judgement,
    ));
    $heat = static fn(JudgedMutants $mutants, int $base): Heat => Heat::of('Money::add', $mutants, Uncovered::Count)
        ->comparedWith(Score::ofHundredths($base));

    expect($heat($judged(MutantJudgement::Survived), 7_500)->drop())->toBe(7_500)
        ->and($heat($judged(MutantJudgement::Killed), 5_000)->drop())->toBe(0)
        ->and($heat($judged(MutantJudgement::Killed), 10_000)->drop())->toBe(0)
        ->and($heat(JudgedMutants::none(), 5_000)->drop())->toBe(0)
        ->and(Heat::of('Money::add', $judged(MutantJudgement::Survived), Uncovered::Count)->drop())->toBe(0);
});
