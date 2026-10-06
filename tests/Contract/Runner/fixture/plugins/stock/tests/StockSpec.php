<?php

declare(strict_types=1);

use Beside\Stock;

it('leaves what was held less what was sold', function (): void {
    expect(new Stock()->left(5, 3))->toBe(2);
});
