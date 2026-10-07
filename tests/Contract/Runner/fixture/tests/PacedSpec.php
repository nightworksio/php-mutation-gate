<?php

declare(strict_types=1);

use Library\Unexecutable\Paced;

it('counts up to a number, a step at a time', function (): void {
    expect(Paced::count(3))->toBe(3);
});
