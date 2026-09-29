<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;

it('is the mutator, its family and the diff of the change', function (): void {
    $mutation = Mutation::of('Infection\\Mutator\\Boolean\\LogicalAnd', MutatorFamily::Logical, "-\$a && \$b\n+\$a || \$b");

    expect($mutation->mutator())->toBe('Infection\\Mutator\\Boolean\\LogicalAnd')
        ->and($mutation->family())->toBe(MutatorFamily::Logical)
        ->and($mutation->diff())->toBe("-\$a && \$b\n+\$a || \$b");
});
