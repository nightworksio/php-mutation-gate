<?php

declare(strict_types=1);

$_ENV['REACH_SHARED'] = '5';

it('sets the variable another test file reads', function (): void {
    expect($_ENV['REACH_SHARED'])->toBe('5');
});
