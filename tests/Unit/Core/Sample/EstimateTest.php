<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Estimate;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\Spread;
use NightWorksIO\MutationGate\Core\Sample\Stratum;
use NightWorksIO\MutationGate\Core\Sample\Tally;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

$sample = static fn(): Sample => Sample::drawn('a1b2c3', Confidence::NinetyFive, 2.0);

// 40 of 50 exact mutants killed, and a stratum of ten units estimated to hold
// 100 mutants, of which three units of ten mutants each killed 22.
$sampled = static fn(): Estimate => Estimate::of(
    Tally::of(40, 50),
    5,
    Stratum::of(10, 100, Spread::of(Tally::of(8, 10), Tally::of(5, 10), Tally::of(9, 10))),
    $sample(),
);

it('weighs the exact mutants and the sampled stratum\'s estimate by their mutants', function () use ($sampled): void {
    $estimate = $sampled();
    $score = $estimate->score();

    expect($score)->toBeInstanceOf(Score::class)
        ->and($score instanceof Score ? $score->hundredths() : 0)->toBe(7_555)
        ->and($estimate->lower())->toBe(6_070)
        ->and($estimate->upper())->toBe(8_526)
        ->and($estimate->isSampled())->toBeTrue();
});

it('counts its units: those sampled, those known exactly, and every unit of the tree', function () use ($sampled, $sample): void {
    $estimate = $sampled();

    expect($estimate->sampledUnits())->toBe(3)
        ->and($estimate->exactUnits())->toBe(5)
        ->and($estimate->totalUnits())->toBe(15)
        ->and($estimate->sample())->toEqual($sample());
});

it('passes where the lower bound reaches the floor, fails where the upper bound is below it, and is undecided between', function (Floor|Exempt|Undeclared $floor, Judgement $judgement) use ($sampled): void {
    expect($sampled()->judgement($floor))->toBe($judgement);
})->with([
    'at the lower bound' => [fn(): Floor => Floor::of(60.70), Judgement::Passed],
    'just above the lower bound' => [fn(): Floor => Floor::of(60.71), Judgement::Undecided],
    'at the upper bound' => [fn(): Floor => Floor::of(85.26), Judgement::Undecided],
    'just above the upper bound' => [fn(): Floor => Floor::of(85.27), Judgement::Failed],
    'no floor' => [fn(): Undeclared => Undeclared::floor(), Judgement::Passed],
    'exempt' => [fn(): Exempt => Exempt::because('generated code'), Judgement::Exempt],
]);

it('is the exact score, with no interval, where no unit of the tree was sampled', function () use ($sample): void {
    $estimate = Estimate::of(Tally::of(2, 3), 4, Stratum::none(), $sample());
    $score = $estimate->score();

    expect($score instanceof Score ? $score->hundredths() : 0)->toBe(6_666)
        ->and($estimate->lower())->toBe(6_666)
        ->and($estimate->upper())->toBe(6_666)
        ->and($estimate->isSampled())->toBeFalse()
        ->and($estimate->totalUnits())->toBe(4)
        ->and($estimate->judgement(Floor::of(66.66)))->toBe(Judgement::Passed)
        ->and($estimate->judgement(Floor::of(66.67)))->toBe(Judgement::Failed);
});

it('has nothing to mutate where neither stratum holds a mutant', function () use ($sample): void {
    $exact = Estimate::of(Tally::none(), 1, Stratum::none(), $sample());
    $sampled = Estimate::of(Tally::none(), 0, Stratum::of(2, 0, Spread::of(Tally::of(0, 0))), $sample());

    expect($exact->score())->toBeInstanceOf(NothingToMutate::class)
        ->and($exact->lower())->toBe(0)
        ->and($exact->upper())->toBe(0)
        ->and($exact->judgement(Floor::of(80)))->toBe(Judgement::NothingToMutate)
        ->and($sampled->score())->toBeInstanceOf(NothingToMutate::class)
        ->and($sampled->lower())->toBe(0)
        ->and($sampled->upper())->toBe(0)
        ->and($sampled->judgement(Floor::of(80)))->toBe(Judgement::NothingToMutate);
});
