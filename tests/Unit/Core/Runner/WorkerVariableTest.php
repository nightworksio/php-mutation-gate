<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\WorkerVariable;

it('names each variable that makes a process another run\'s worker, each one other runs withhold', function (): void {
    expect(WorkerVariable::names())->toBe(['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'LARAVEL_PARALLEL_TESTING']);

    foreach (WorkerVariable::names() as $name) {
        expect(preg_match(Withheld::otherRuns()->pattern(), $name))->toBe(1);
    }
});
