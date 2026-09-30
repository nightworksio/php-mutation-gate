<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Transcript;

it('keeps what each command printed, in the order they ran', function (): void {
    $transcript = Transcript::empty();
    $transcript->keep('first');
    $transcript->keep('second');

    expect($transcript->printed())->toBe("first\nsecond");
});

it('holds nothing before a command has run', function (): void {
    expect(Transcript::empty()->printed())->toBe('');
});
