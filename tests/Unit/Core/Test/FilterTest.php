<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Filter;

it('holds its pattern', function (): void {
    expect(Filter::matching('KernelTest|BootTest')->pattern())->toBe('KernelTest|BootTest');
});

it('selects no test at all with the filter of nothing', function (): void {
    $pattern = Filter::nothing()->pattern();

    expect($pattern)->toBe('(?!)')
        ->and(preg_match(sprintf('/%s/', $pattern), 'Tests\\MoneyTest::adds'))->toBe(0)
        ->and(preg_match(sprintf('/%s/', $pattern), ''))->toBe(0);
});
