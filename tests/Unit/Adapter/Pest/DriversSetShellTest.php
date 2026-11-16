<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\DriversSetShell;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGate\Tests\Support\SideBySideShell;

const DRIVERS_SET = ['PCOV_ENABLED' => '0'];

/**
 * What each command a shell was handed through the drivers' shell was started with of the drivers' variable.
 *
 * @return list<string|false>
 */
function driversSeen(ShellFake $fake): array
{
    return array_map(static fn(Command $command): string|false => $command->environment()['PCOV_ENABLED'] ?? false, $fake->commands());
}

it('leaves a shell as it is where the drivers set nothing', function (): void {
    $fake = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(DriversSetShell::over($fake, []))->toBe($fake);
});

it('starts every command with the drivers\' variables, run, run side by side or started, wherever it is moved', function (bool $starts): void {
    $fake = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $shell = DriversSetShell::over($starts ? $fake : new SideBySideShell($fake), DRIVERS_SET)->in('/elsewhere');
    $slots = WorkerSlots::of(ProcessCount::of(2), 'drivers');

    $shell->run(Command::of('one'));
    $shell->sideBySide($slots, Unlimited::time(), Command::of('two'));
    $started = $shell instanceof DriversSetShell ? $shell->started($slots, Unlimited::time(), Command::of('three'))->ends() : null;

    expect(driversSeen($fake))->toBe(['0', '0', '0'])
        ->and(array_map(static fn(Command $command): string => $command->arguments()[0], $fake->commands()))->toBe(['one', 'two', 'three'])
        ->and($started instanceof ProcessEnds ? [...$started] : [])->toHaveCount(1)
        ->and($fake->handled())->toBe($starts ? [[true, false]] : [])
        ->and($fake->directories())->toBe(['/elsewhere']);
})->with([
    'a shell that starts commands' => [true],
    'a shell that runs them side by side' => [false],
]);
