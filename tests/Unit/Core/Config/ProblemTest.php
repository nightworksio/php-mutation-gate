<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Problem;

it('is a mistake at its path', function (): void {
    $problem = Problem::at('trees[1].floor', 'expected a number from 0 to 100, got "80"');

    expect($problem->path())->toBe('trees[1].floor')
        ->and($problem->message())->toBe('expected a number from 0 to 100, got "80"');
});

it('says what a value should have been, and what it was', function (): void {
    $problem = Problem::mismatch('region', 'a region', '"eu-west-1/x"');

    expect($problem->path())->toBe('region')
        ->and($problem->message())->toBe('expected a region, got "eu-west-1/x"');
});
