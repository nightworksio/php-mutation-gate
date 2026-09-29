<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\NotAPercentage;

it('names the number that is not a percentage', function (): void {
    expect(NotAPercentage::of(100.01)->getMessage())->toBe('100.01 is not a percentage from 0 to 100.');
});
