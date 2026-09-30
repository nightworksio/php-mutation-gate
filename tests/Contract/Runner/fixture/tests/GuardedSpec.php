<?php

declare(strict_types=1);

use Library\Unexecutable\Guarded;

it('holds Guarded without asserting its value', function (): void {
    expect(Guarded::SEEN)->toBeInt();
})->group('holds:src/Unexecutable/Guarded.php');

it('asserts Guarded\'s value outside the group', function (): void {
    expect(Guarded::SEEN)->toBe(4);
});
