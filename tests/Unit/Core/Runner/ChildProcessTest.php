<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;

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
