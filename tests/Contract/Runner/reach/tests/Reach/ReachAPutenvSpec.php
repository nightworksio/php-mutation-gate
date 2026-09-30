<?php

declare(strict_types=1);

putenv('REACH_PUT=5');

it('puts the variable another test file reads in the environment', function (): void {
    expect(getenv('REACH_PUT'))->toBe('5');
});
