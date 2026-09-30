<?php

declare(strict_types=1);

use Library\Reach;

it('relies on a hook another test file registers', function (): void {
    expect($this->reached ?? null)->toBe(5)
        ->and(Reach::amount(5))->toBeInt();
});
