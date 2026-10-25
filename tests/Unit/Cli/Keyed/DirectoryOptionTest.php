<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Keyed\DirectoryOption;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use Symfony\Component\Console\Input\ArrayInput;

it('takes the directory the option names, else the one it stands for where the option is left empty or out', function (): void {
    $in = static fn(array $given): string|CannotJudge => DirectoryOption::in(
        new ArrayInput(['command' => 'fetch', ...$given]),
        'fetch',
        'to',
        Path::of('.mutation-gate/ledger'),
    );

    expect([$in(['--to' => 'kept']), $in(['--to' => '']), $in([])])
        ->toBe(['kept', '.mutation-gate/ledger', '.mutation-gate/ledger']);
});
