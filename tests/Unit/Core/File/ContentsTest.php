<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;

it('holds the text of a file', function (): void {
    expect(Contents::of("{\"format\": 1}\n")->text())->toBe("{\"format\": 1}\n");
});

it('holds no text for a file not read', function (): void {
    expect(Contents::none()->text())->toBe('');
});
