<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Living;
use NightWorksIO\MutationGate\Tests\Support\Passing;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('says a process has ended where its state is an ended one, whatever its command is named', function (string $stat, bool $runs): void {
    $file = sprintf('%s/%%d.stat', Scratch::directory());
    $own = (int) getmypid();

    if ($stat !== '') {
        file_put_contents(sprintf($file, $own), sprintf('%d %s', $own, $stat));
    }

    expect(Living::hasEnded($own, $file))->toBe(! $runs);
})->with([
    'running' => ['(php) R 1 2 3', true],
    'sleeping' => ['(php) S 1 2 3', true],
    'ended, not collected' => ['(php) Z 1 2 3', false],
    'going' => ['(php) X 1 2 3', false],
    'a command with a bracket and a state letter in it' => ['(a) Z b) S 1 2 3', true],
    'a command named for an ended state' => ['(Z) S 1 2 3', true],
    'no state' => ['php', true],
    'no file' => ['', true],
]);

it('says nothing has ended, and raises nothing, where the state can be found and not read', function (): void {
    $own = (int) getmypid();
    $unreadable = sprintf('%s/%%d', Scratch::directory());
    mkdir(sprintf($unreadable, $own));
    $raised = [];
    set_error_handler(static function (int $level, string $said) use (&$raised): bool {
        $raised[] = $said;

        return true;
    });

    try {
        $ended = Living::hasEnded($own, $unreadable);
    } finally {
        restore_error_handler();
    }

    expect($ended)->toBeFalse()
        ->and($raised)->toBe([]);
});

it('says a child that ended and was not collected has ended, where Linux tells its state', function (): void {
    $started = proc_open(['sleep', '0.2'], [], $pipes);
    $child = is_resource($started) ? proc_get_status($started)['pid'] : Passing::NONE;
    $until = hrtime(as_number: true) + 5_000_000_000;
    $ended = false;

    while (! $ended && hrtime(as_number: true) < $until) {
        $ended = Living::hasEnded($child);
    }

    if (is_resource($started)) {
        proc_close($started);
    }

    expect($ended)->toBeTrue();
})->skip(fn(): bool => ! is_readable(sprintf(Living::STAT, (int) getmypid())), 'this system tells no process state in /proc');
