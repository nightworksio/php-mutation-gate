<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapLimits;

it('keeps a map whose text is at most 85,000,000 bytes, twice what the gate\'s own suite measures, and gzips to at most 4,200,000', function (): void {
    $limits = MapLimits::standard();

    expect($limits->unpacked())->toBe(85_000_000)
        ->and($limits->packed())->toBe(4_200_000)
        ->and($limits->admits(str_repeat('x', 85_000_000), 'gz'))->toBeTrue()
        ->and($limits->admits(str_repeat('x', 85_000_001), 'gz'))->toBeFalse()
        ->and($limits->admits('{}', str_repeat('x', 4_200_000)))->toBeTrue()
        ->and($limits->admits('{}', str_repeat('x', 4_200_001)))->toBeFalse();
});
