<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\NothingToMutate;

it('stands for a set with no mutant in it', function (): void {
    expect(NothingToMutate::found())->toBeInstanceOf(NothingToMutate::class);
});
