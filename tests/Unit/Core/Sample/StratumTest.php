<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\Spread;
use NightWorksIO\MutationGate\Core\Sample\Stratum;
use NightWorksIO\MutationGate\Core\Sample\Tally;

$three = static fn(): Spread => Spread::of(Tally::of(8, 10), Tally::of(5, 10), Tally::of(9, 10));

it('holds no unit and no mutant where a tree is known exactly', function (): void {
    $stratum = Stratum::none();

    expect($stratum->units())->toBe(0)
        ->and($stratum->sampledUnits())->toBe(0)
        ->and($stratum->mutants())->toBe(0)
        ->and($stratum->share())->toBe(0.0);
});

it('holds its units, those sampled, its estimated mutants, and the share its sample killed', function () use ($three): void {
    $stratum = Stratum::of(10, 100, $three());

    expect($stratum->units())->toBe(10)
        ->and($stratum->sampledUnits())->toBe(3)
        ->and($stratum->mutants())->toBe(100)
        ->and($stratum->share())->toEqualWithDelta(22 / 30, 1e-12);
});

it('bounds the share by Wilson\'s interval on the sample\'s effective size, corrected for the share of units sampled', function () use ($three): void {
    $bounds = Stratum::of(10, 100, $three())->bounds(Confidence::NinetyFive);

    expect($bounds->lower())->toEqualWithDelta(0.5105484141931372, 1e-12)
        ->and($bounds->upper())->toEqualWithDelta(0.878787925256962, 1e-12);
});

it('takes the design effect as 1 where the units vary no more than independent mutants would', function (Spread $sampled, float $lower, float $upper): void {
    $bounds = Stratum::of(10, 100, $sampled)->bounds(Confidence::NinetyFive);

    expect($bounds->lower())->toEqualWithDelta($lower, 1e-12)
        ->and($bounds->upper())->toEqualWithDelta($upper, 1e-12);
})->with([
    'units alike' => [fn(): Spread => Spread::of(Tally::of(5, 10), Tally::of(5, 10), Tally::of(6, 10)), 0.3874784585527984, 0.67370416264973],
    'units the same' => [fn(): Spread => Spread::of(Tally::of(5, 10), Tally::of(5, 10)), 0.3175224322423106, 0.6824775677576894],
    'every mutant killed' => [fn(): Spread => Spread::of(Tally::of(10, 10), Tally::of(10, 10)), 0.8668077490609515, 0.9999999999999999],
    'one unit' => [fn(): Spread => Spread::of(Tally::of(7, 10)), 0.4107292004329657, 0.8865069579474594],
]);

it('knows its share exactly where every unit of it was sampled', function () use ($three): void {
    $bounds = Stratum::of(3, 30, $three())->bounds(Confidence::NinetyFive);

    expect($bounds->lower())->toEqualWithDelta(22 / 30, 1e-12)
        ->and($bounds->upper())->toEqualWithDelta(22 / 30, 1e-12);
});

it('allows any share where its sample counted no mutant', function (): void {
    $bounds = Stratum::of(10, 100, Spread::of(Tally::of(0, 0), Tally::of(0, 0)))->bounds(Confidence::NinetyFive);

    expect($bounds->lower())->toBe(0.0)
        ->and($bounds->upper())->toBe(1.0);
});
