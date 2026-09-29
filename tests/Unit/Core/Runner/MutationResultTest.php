<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

it('holds the mutants a run recorded and how many it skipped without a record', function (): void {
    $mutants = Mutants::none();
    $result = MutationResult::of($mutants, 3);

    expect($result->mutants())->toBe($mutants)
        ->and($result->skipped())->toBe(3);
});
