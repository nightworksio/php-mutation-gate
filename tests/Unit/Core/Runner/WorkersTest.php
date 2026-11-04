<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Workers;

it('forks the mutants under fork and mutants, and the tail under fork alone', function (Workers $workers, bool $mutants, bool $tail): void {
    expect([$workers->forksMutants(), $workers->forksTheTail()])->toBe([$mutants, $tail]);
})->with([
    'fork' => [Workers::Fork, true, true],
    'mutants' => [Workers::Mutants, true, false],
    'fresh' => [Workers::Fresh, false, false],
]);
