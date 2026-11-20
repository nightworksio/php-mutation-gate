<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\MadeMutants;

it('counts the mutants it makes alone, and none where it makes every one', function (): void {
    $only = MadeMutants::only('a1', 'b2', 'c3');

    expect(count($only))->toBe(3)
        ->and([...$only])->toBe(['a1', 'b2', 'c3'])
        ->and(count(MadeMutants::every()))->toBe(0);
});
