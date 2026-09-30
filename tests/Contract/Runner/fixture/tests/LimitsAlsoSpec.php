<?php

declare(strict_types=1);

use Library\Unexecutable\Limits;

// A second test file that covers Limits without asserting its value.
it('covers Limits too', function (): void {
    expect(Limits::of(Limits::class))->toBeInt();
});
