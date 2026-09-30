<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\AnyMutator;

it('is the one way to say every mutator, whoever names it', function (): void {
    expect(AnyMutator::of())->toEqual(AnyMutator::of())
        ->and(AnyMutator::of())->toBeInstanceOf(AnyMutator::class);
});
