<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\Halting;

it('hears the signal sent to this process once listened to, and handles it as before once let go', function (): void {
    $halting = Halting::listened();
    $before = $halting->heard();
    Halting::halt();

    expect($before)->toBeFalse()
        ->and($halting->heard())->toBeTrue();

    $halting->letGo();

    expect(pcntl_signal_get_handler(SIGUSR2))->toBe(SIG_DFL);
})->skip(! Halting::signals(), 'only a PHP with pcntl and posix hears signals');

it('hands the signal on to the handler it had before', function (): void {
    $handed = [];
    pcntl_signal(SIGUSR2, static function (int $signal) use (&$handed): void {
        $handed[] = $signal;
    });
    $halting = Halting::listened();
    Halting::halt();
    $heard = $halting->heard();
    $halting->letGo();
    pcntl_signal(SIGUSR2, SIG_DFL);

    expect($heard)->toBeTrue()
        ->and($handed)->toBe([SIGUSR2]);
})->skip(! Halting::signals(), 'only a PHP with pcntl and posix hears signals');
