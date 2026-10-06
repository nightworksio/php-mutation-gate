<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapLimits;

it('keeps a map whose text is at most 31,000,000 bytes, twice what the gate\'s own suite measures, and gzips to at most 1,500,000', function (): void {
    $limits = MapLimits::standard();

    expect($limits->unpacked())->toBe(31_000_000)
        ->and($limits->packed())->toBe(1_500_000)
        ->and($limits->admits(str_repeat('x', 31_000_000), 'gz'))->toBeTrue()
        ->and($limits->admits(str_repeat('x', 31_000_001), 'gz'))->toBeFalse()
        ->and($limits->admits('{}', str_repeat('x', 1_500_001)))->toBeFalse();
});
