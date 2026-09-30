<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

it('spells each judgement as the reports write it', function (): void {
    expect(array_map(static fn(Judgement $judgement): string => $judgement->value, Judgement::cases()))
        ->toBe(['passed', 'failed', 'nothing-to-mutate', 'exempt']);
});

it('holds a score to its floor', function (Floor|Exempt|Undeclared $floor, Score|NothingToMutate $score, Judgement $judgement): void {
    expect(Judgement::of($floor, $score))->toBe($judgement);
})->with([
    'above the floor' => [Floor::ofHundredths(8_000), Score::ofHundredths(8_001), Judgement::Passed],
    'at the floor' => [Floor::ofHundredths(8_000), Score::ofHundredths(8_000), Judgement::Passed],
    'below the floor' => [Floor::ofHundredths(8_000), Score::ofHundredths(7_999), Judgement::Failed],
    'nothing to mutate under a floor' => [Floor::ofHundredths(10_000), NothingToMutate::found(), Judgement::NothingToMutate],
    'an exempt tree' => [Exempt::because('Generated code'), NothingToMutate::found(), Judgement::Exempt],
    'an exempt tree with a score' => [Exempt::because('Generated code'), Score::ofHundredths(0), Judgement::Exempt],
    'no floor anywhere' => [Undeclared::floor(), Score::ofHundredths(0), Judgement::Passed],
]);
