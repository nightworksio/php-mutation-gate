<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ceiling;

it('admits an argument shorter in bytes than the most it may take, however few characters it has', function (): void {
    expect(Ceiling::admits('a', 2))->toBeTrue()
        ->and(Ceiling::admits('ab', 2))->toBeFalse()
        ->and(Ceiling::admits('é', 2))->toBeFalse()
        ->and(Ceiling::admits(str_repeat('a', Ceiling::BYTES - 1)))->toBeTrue()
        ->and(Ceiling::admits(str_repeat('a', Ceiling::BYTES)))->toBeFalse();
});
