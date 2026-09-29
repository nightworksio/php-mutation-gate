<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Proof;

it('is a unit\'s mutants under the key of everything its verdict read', function (): void {
    $mutants = Mutants::none();
    $proof = Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), $mutants);

    expect($proof->key()->value())->toBe('9c1e')
        ->and($proof->unit()->value())->toBe('src/Money.php')
        ->and($proof->mutants())->toBe($mutants);
});
