<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\Failure;

it('is the sentence every report prints for it', function (): void {
    expect(Failure::that('The ignore of src/Money.php:12 matched no mutant.')->text())
        ->toBe('The ignore of src/Money.php:12 matched no mutant.');
});
