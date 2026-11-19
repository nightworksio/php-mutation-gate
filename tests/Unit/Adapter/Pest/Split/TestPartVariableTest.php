<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\TestPartVariable;
use NightWorksIO\MutationGate\Core\Test\TestPart;

it('tells a run its part of the test files, and nothing of every test file', function (): void {
    expect(TestPartVariable::told(TestPart::of(2, 3)))->toBe(['MUTATION_GATE_SPLIT_PART' => '2/3'])
        ->and(TestPartVariable::told(TestPart::whole()))->toBe([]);
});
