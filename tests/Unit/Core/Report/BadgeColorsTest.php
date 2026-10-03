<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

it('colours a score by the band it reaches, red below them all', function (int $hundredths, string $color): void {
    expect(BadgeColors::defaults()->colorOf(Score::ofHundredths($hundredths)))->toBe($color);
})->with([
    'every mutant killed' => [10_000, 'brightgreen'],
    'exactly 90' => [9_000, 'brightgreen'],
    'just under 90' => [8_999, 'green'],
    'exactly 80' => [8_000, 'green'],
    'just under 80' => [7_999, 'yellow'],
    'exactly 70' => [7_000, 'yellow'],
    'just under 70' => [6_999, 'orange'],
    'exactly 60' => [6_000, 'orange'],
    'just under 60' => [5_999, 'red'],
    'nothing killed' => [0, 'red'],
]);

it('takes the bands a config gives, in any order and to hundredths', function (): void {
    $colors = BadgeColors::of(['yellow' => 50, 'blue' => 99.5]);

    expect($colors->colorOf(Score::ofHundredths(9_950)))->toBe('blue')
        ->and($colors->colorOf(Score::ofHundredths(9_949)))->toBe('yellow')
        ->and($colors->colorOf(Score::ofHundredths(4_999)))->toBe('red');
});

it('greys a badge with nothing to mutate', function (): void {
    expect(BadgeColors::defaults()->colorOf(NothingToMutate::found()))->toBe('lightgrey');
});
