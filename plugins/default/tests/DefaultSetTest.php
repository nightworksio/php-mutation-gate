<?php

declare(strict_types=1);

use NightWorksIO\MutationGateDefault\DefaultSet;

it('names the set default, and each mutator within it', function (): void {
    expect(DefaultSet::name()->value())->toBe('default')
        ->and(DefaultSet::mutator('PlusToMinus')->value())->toBe('default/PlusToMinus');
});
