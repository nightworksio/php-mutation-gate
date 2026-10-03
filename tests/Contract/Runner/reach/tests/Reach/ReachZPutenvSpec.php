<?php

declare(strict_types=1);

use Library\Reach;

it('reads a variable another test file puts in the environment', function (): void {
    expect(getenv('REACH_PUT'))->toBe('5')
        ->and(Reach::amount(5))->toBeInt();
});
