<?php

declare(strict_types=1);

use Library\Unexecutable\Crowded;

it('touches the crowded file', function (): void {
    expect(new Crowded()->touch())->toBe(1);
});
