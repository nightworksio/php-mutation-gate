<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Defect;

it('names a failure and an error as PHPUnit does', function (): void {
    expect(array_map(static fn(Defect $defect): string => $defect->value, Defect::cases()))
        ->toBe(['failure', 'error']);
});

it('tells a defect from any other name', function (): void {
    expect(Defect::names('failure'))->toBeTrue()
        ->and(Defect::names('error'))->toBeTrue()
        ->and(Defect::names('skipped'))->toBeFalse()
        ->and(Defect::names('Failure'))->toBeFalse();
});
