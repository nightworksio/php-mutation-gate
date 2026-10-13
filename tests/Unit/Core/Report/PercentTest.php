<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

it('prints a score or a floor with two decimals, never rounded', function (Score|Floor $value, string $printed): void {
    expect(Percent::of($value))->toBe($printed);
})->with([
    'a whole score' => [fn(): Score => Score::ofHundredths(10_000), '100.00%'],
    'hundredths' => [fn(): Score => Score::ofHundredths(8_741), '87.41%'],
    'a leading zero after the point' => [fn(): Score => Score::ofHundredths(8_305), '83.05%'],
    'nothing killed' => [fn(): Score => Score::ofHundredths(0), '0.00%'],
    'a floor, truncated' => [fn(): Floor => Floor::of(83.419), '83.41%'],
]);

it('never prints a set with nothing to mutate as a percentage', function (): void {
    expect(Percent::of(NothingToMutate::found()))->toBe('nothing to mutate');
});

it('prints the change from one score to another, signed, in points', function (int $from, int $to, string $change): void {
    expect(Percent::change(Score::ofHundredths($from), Score::ofHundredths($to)))->toBe($change);
})->with([
    'up' => [8_000, 8_120, '+1.20'],
    'up by a hundredth' => [8_000, 8_001, '+0.01'],
    'down by a hundredth' => [8_000, 7_999, '-0.01'],
    'down by hundredths' => [8_000, 7_995, '-0.05'],
    'down by points' => [9_000, 7_000, '-20.00'],
    'unchanged' => [8_000, 8_000, '±0.00'],
]);
