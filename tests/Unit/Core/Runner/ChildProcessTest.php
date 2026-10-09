<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Runner\Ran;

it('says how a command exited, what it wrote, and what it said on its error output', function (): void {
    $failed = ChildProcess::exited(2, '{"issues": []}', 'ERROR --substitute: no such file');

    expect([$failed->exit(), $failed->output(), $failed->errors(), $failed->succeeded(), $failed->said()])->toBe([
        2,
        '{"issues": []}',
        'ERROR --substitute: no such file',
        false,
        'exit 2: ERROR --substitute: no such file',
    ])->and(ChildProcess::exited(0, 'ok', '')->succeeded())->toBeTrue();
});

it('says why a command never started, and that it did not run', function (): void {
    $never = ChildProcess::neverStarted('The provided cwd "/nowhere" does not exist.');

    expect([$never->exit(), $never->output(), $never->succeeded(), $never->said()])->toEqual([
        NotGiven::value(),
        '',
        false,
        'it did not run: The provided cwd "/nowhere" does not exist.',
    ]);
});

it('was stopped at its limit, with what it wrote until then, and says so', function (): void {
    $stopped = ChildProcess::stopped('{"half', 'analysing');

    expect([$stopped->wasStopped(), $stopped->exit(), $stopped->output(), $stopped->succeeded(), $stopped->said()])
        ->toEqual([true, NotGiven::value(), '{"half', false, 'it was stopped at its limit: analysing'])
        ->and(ChildProcess::exited(1, '', '')->wasStopped())->toBeFalse()
        ->and(ChildProcess::neverStarted('no binary')->wasStopped())->toBeFalse();
});

it('reads a process the Processes port ran: its report, its errors, and how it ended', function (): void {
    $exited = ChildProcess::of(Ran::exited(2, '{"issues": []}boom', '{"issues": []}'));
    $stopped = ChildProcess::of(Ran::stopped('{partial', '{partial'));
    $never = ChildProcess::of(Ran::finished(succeeded: false, output: 'The program cannot be started.'));
    $mixed = ChildProcess::of(Ran::exited(0, 'all of it'));

    expect([$exited->exit(), $exited->output(), $exited->errors(), $exited->wasStopped()])->toEqual([2, '{"issues": []}', 'boom', false])
        ->and([$stopped->wasStopped(), $stopped->output(), $stopped->errors()])->toBe([true, '{partial', ''])
        ->and([$never->exit(), $never->said()])->toEqual([NotGiven::value(), 'it did not run: The program cannot be started.'])
        ->and([$mixed->output(), $mixed->errors()])->toBe(['all of it', '']);
});
