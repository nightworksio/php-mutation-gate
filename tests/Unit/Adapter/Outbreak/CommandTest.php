<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\Command;

it('hands the binary each command as its arguments', function (): void {
    expect(Command::Contract->words())->toBe(['contract'])
        ->and(Command::Vouch->words())->toBe(['map', 'vouch'])
        ->and(Command::Split->words())->toBe(['map', 'split']);
});
