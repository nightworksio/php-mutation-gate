<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\Attached;
use NightWorksIO\MutationGate\Core\NotGiven;

it('waits for a program on its own streams and answers its exit status', function (): void {
    expect(Attached::exitOf([PHP_BINARY, '-r', 'exit(3);']))->toBe(3)
        ->and(Attached::exitOf([PHP_BINARY, '-r', 'exit(0);']))->toBe(0);
})->group('holds:src/Adapter/Process/Attached.php');

it('answers none where the program cannot start', function (): void {
    set_error_handler(static fn(): bool => true);
    $status = Attached::exitOf(['/no/such/program']);
    restore_error_handler();

    expect($status)->toBeInstanceOf(NotGiven::class);
})->group('holds:src/Adapter/Process/Attached.php');
