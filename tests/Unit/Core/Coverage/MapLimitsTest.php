<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapLimits;

it('keeps a map whose text is at most 201,200,000 bytes, twice what the gate\'s own suite measures, and gzips to at most 8,100,000', function (): void {
    $limits = MapLimits::standard();

    expect($limits->unpacked())->toBe(201_200_000)
        ->and($limits->packed())->toBe(8_100_000)
        ->and($limits->admits(str_repeat('x', 201_200_000), 'gz'))->toBeTrue()
        ->and($limits->admits(str_repeat('x', 201_200_001), 'gz'))->toBeFalse()
        ->and($limits->admits('{}', str_repeat('x', 8_100_000)))->toBeTrue()
        ->and($limits->admits('{}', str_repeat('x', 8_100_001)))->toBeFalse();
});
