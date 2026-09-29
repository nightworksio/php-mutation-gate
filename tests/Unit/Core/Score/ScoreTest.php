<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\NotAPercentage;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

it('counts killed out of counted in hundredths, truncated rather than rounded', function (int $killed, int $counted, int $hundredths): void {
    expect(Score::of($killed, $counted))->toEqual(Score::ofHundredths($hundredths));
})->with([
    'two of three' => [2, 3, 6_666],
    'every one' => [7, 7, 10_000],
    'none' => [0, 4, 0],
    'one of eight' => [1, 8, 1_250],
]);

it('has no score when nothing was counted, which is not every mutant killed', function (): void {
    expect(Score::of(0, 0))->toEqual(NothingToMutate::found())
        ->and(Score::of(0, 0))->not->toEqual(Score::ofHundredths(10_000));
});

it('gives back the hundredths and the percentage it holds', function (): void {
    $score = Score::ofHundredths(8741);

    expect($score->hundredths())->toBe(8741)
        ->and($score->percent())->toBe(87.41);
});

it('takes a score in hundredths from 0 to 10000', function (int $hundredths): void {
    expect(Score::ofHundredths($hundredths)->hundredths())->toBe($hundredths);
})->with([0, 1, 9_999, 10_000]);

it('refuses a score outside 0 to 100', function (int $hundredths, string $message): void {
    expect(static fn(): Score => Score::ofHundredths($hundredths))->toThrow(NotAPercentage::class, $message);
})->with([
    'below' => [-1, '-0.01 is not a percentage from 0 to 100.'],
    'above' => [10_001, '100.01 is not a percentage from 0 to 100.'],
]);

it('refuses more killed than counted', function (): void {
    expect(static fn(): Score|NothingToMutate => Score::of(3, 2))->toThrow(NotAPercentage::class, '150 is not a percentage from 0 to 100.');
});
