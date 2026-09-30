<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;

it('is a unit\'s mutants under the key of everything its verdict read, with the run that proved it', function (): void {
    $mutants = Mutants::none();
    $run = Run::of('github:5813/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));
    $proof = Proof::of(Digest::of('9c1e'), Path::of('src/Money.php'), $mutants, $run);

    expect($proof->key()->value())->toBe('9c1e')
        ->and($proof->unit()->value())->toBe('src/Money.php')
        ->and($proof->mutants())->toBe($mutants)
        ->and($proof->run())->toBe($run);
});
