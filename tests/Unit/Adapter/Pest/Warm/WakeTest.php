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

it('ends a pause at once for a wake that came before it, and pauses as long as asked where none came', function (): void {
    Wake::heard();
    Wake::sent((int) getmypid());
    $woken = hrtime(as_number: true);
    Wake::paused(5_000_000);
    $woken = hrtime(as_number: true) - $woken;
    $waited = hrtime(as_number: true);
    Wake::paused(50_000);
    $waited = hrtime(as_number: true) - $waited;

    expect($woken)->toBeLessThan(2_000_000_000)
        ->and($waited)->toBeGreaterThanOrEqual(40_000_000);
});

it('pauses as usleep does where it hears no wake', function (): void {
    $waited = hrtime(as_number: true);
    Wake::paused(20_000);

    expect(hrtime(as_number: true) - $waited)->toBeGreaterThanOrEqual(15_000_000);
});
