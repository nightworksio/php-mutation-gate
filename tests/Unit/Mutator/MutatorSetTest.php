<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;

it('holds each mutator class once, in the order given', function (): void {
    $set = MutatorSet::of(PlusToMinus::class, RemoveEcho::class, PlusToMinus::class);

    expect($set)->toHaveCount(2)
        ->and(iterator_to_array($set, preserve_keys: false))->toBe([PlusToMinus::class, RemoveEcho::class]);
});
