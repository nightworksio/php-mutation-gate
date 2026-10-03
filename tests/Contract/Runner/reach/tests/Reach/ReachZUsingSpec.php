<?php

declare(strict_types=1);

use Library\Reach;

it('relies on a trait another test file makes it use', function (): void {
    expect($this->reached ?? null)->toBe(5)
        ->and(Reach::amount(5))->toBeInt();
});
