<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;

it('answers the value of a variable the CI set', function (): void {
    $variables = Variables::of(['SHARD' => '2']);

    expect($variables->has('SHARD'))->toBeTrue()
        ->and($variables->valueOf('SHARD'))->toBe('2');
});

it('reads a variable that is not set as nothing', function (): void {
    $variables = Variables::of([]);

    expect($variables->has('SHARD'))->toBeFalse()
        ->and($variables->valueOf('SHARD'))->toBe('');
});

it('reads a variable set to nothing as not set', function (): void {
    expect(Variables::of(['SHARD' => ''])->has('SHARD'))->toBeFalse();
});
