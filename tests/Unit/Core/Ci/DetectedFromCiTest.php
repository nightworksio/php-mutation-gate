<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\DetectedFromCi;

it('is the one choice left to the CI, whoever names it', function (): void {
    expect(DetectedFromCi::choice())->toEqual(DetectedFromCi::choice())
        ->and(DetectedFromCi::choice())->toBeInstanceOf(DetectedFromCi::class);
});
