<?php

declare(strict_types=1);

use Infection\Mutator\Boolean\LogicalAnd;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;

it('is the mutator, its family and the diff of the change', function (): void {
    $mutation = Mutation::of(LogicalAnd::class, MutatorFamily::Logical, "-\$a && \$b\n+\$a || \$b");

    expect($mutation->mutator())->toBe(LogicalAnd::class)
        ->and($mutation->family())->toBe(MutatorFamily::Logical)
        ->and($mutation->diff())->toBe("-\$a && \$b\n+\$a || \$b");
});
