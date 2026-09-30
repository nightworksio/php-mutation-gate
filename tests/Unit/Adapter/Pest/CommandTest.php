<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('is a program with its arguments, no environment of its own and no deadline', function (): void {
    $command = Command::of('git', 'status');

    expect($command->arguments())->toBe(['git', 'status'])
        ->and($command->environment())->toBe([])
        ->and($command->deadline())->toEqual(Unlimited::time());
});

it('lists its arguments in order, however they were spread', function (): void {
    expect(Command::of(...['program' => 'git', 'command' => 'status'])->arguments())->toBe(['git', 'status']);
});

it('runs Pest on the PHP that runs the gate', function (): void {
    expect(Command::pest('--list-groups')->arguments())->toBe([PHP_BINARY, 'vendor/bin/pest', '--list-groups']);
});

it('adds to its environment, a later value replacing an earlier one', function (): void {
    $command = Command::of('pest')->with(['A' => '1', 'B' => '2'])->with(['B' => '3']);

    expect($command->environment())->toBe(['A' => '1', 'B' => '3'])
        ->and($command->arguments())->toBe(['pest']);
});

it('takes a deadline, keeping what it runs', function (): void {
    $command = Command::of('pest')->with(['A' => '1'])->within(Seconds::of(90.0));

    expect($command->deadline())->toEqual(Seconds::of(90.0))
        ->and($command->arguments())->toBe(['pest'])
        ->and($command->environment())->toBe(['A' => '1'])
        ->and($command->within(Unlimited::time())->deadline())->toEqual(Unlimited::time());
});
