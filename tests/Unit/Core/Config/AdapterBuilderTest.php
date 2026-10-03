<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\AdapterBuilder;

it('names only classes of the PHP builder that choose an adapter by its name', function (): void {
    $missing = array_values(array_filter(
        AdapterBuilder::cases(),
        static fn(AdapterBuilder $builder): bool => ! method_exists(sprintf('NightWorksIO\MutationGate\Config\%s', $builder->value), 'uses'),
    ));

    expect($missing)->toBe([]);
});
