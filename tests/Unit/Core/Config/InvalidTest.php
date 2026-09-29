<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;

it('holds every mistake at once, in the order found', function (): void {
    $invalid = Invalid::because(Problem::at('newcode', 'unknown key; did you mean newCode?'), ...['b' => Problem::at('trees[1].floor', 'expected a number')]);

    expect(array_map(static fn(Problem $problem): string => $problem->path(), iterator_to_array($invalid, preserve_keys: true)))->toBe(['newcode', 'trees[1].floor'])
        ->and($invalid)->toHaveCount(2);
});
