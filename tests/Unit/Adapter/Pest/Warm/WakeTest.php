<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Wake;

afterEach(function (): void {
    Wake::unheard();
});

it('hears a wake and a child\'s end, each by a handler of its own, and neither once unheard', function (): void {
    Wake::heard();
    $heard = [pcntl_signal_get_handler(Wake::SIGNAL), pcntl_signal_get_handler(SIGCHLD)];
    Wake::unheard();

    expect($heard)->each->toBeInstanceOf(Closure::class)
        ->and([pcntl_signal_get_handler(Wake::SIGNAL), pcntl_signal_get_handler(SIGCHLD)])->toBe([SIG_DFL, SIG_DFL]);
});

it('ends nothing as a wake reaches the process that hears it', function (): void {
    Wake::heard();
    Wake::sent((int) getmypid());

    expect(pcntl_signal_dispatch())->toBeTrue();
});

it('wakes the process it names with a signal a process with no handler for it ignores', function (): void {
    $caught = [];
    pcntl_signal(Wake::SIGNAL, static function (int $signal) use (&$caught): void {
        $caught[] = $signal;
    });

    Wake::sent((int) getmypid());
    pcntl_signal_dispatch();

    expect($caught)->toBe([SIGURG]);
});
