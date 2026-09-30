<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('runs a script on the PHP that runs the gate, with no environment of its own and no deadline', function (): void {
    $command = Command::php('vendor/bin/phpunit', '--list-groups');

    expect($command->arguments())->toBe([PHP_BINARY, 'vendor/bin/phpunit', '--list-groups'])
        ->and($command->environment())->toBe([])
        ->and($command->deadline())->toEqual(Unlimited::time());
});

it('lists its arguments in order, however they were spread', function (): void {
    expect(Command::php(...['script' => 'infection', 'option' => '--no-progress'])->arguments())
        ->toBe([PHP_BINARY, 'infection', '--no-progress']);
});

it('adds to its environment, a later value replacing an earlier one', function (): void {
    $command = Command::php('phpunit')->with(['A' => '1', 'B' => '2'])->with(['B' => '3']);

    expect($command->environment())->toBe(['A' => '1', 'B' => '3'])
        ->and($command->arguments())->toBe([PHP_BINARY, 'phpunit']);
});

it('takes a deadline, keeping what it runs', function (): void {
    $command = Command::php('infection')->with(['A' => '1'])->within(Seconds::of(90.0));

    expect($command->deadline())->toEqual(Seconds::of(90.0))
        ->and($command->arguments())->toBe([PHP_BINARY, 'infection'])
        ->and($command->environment())->toBe(['A' => '1'])
        ->and($command->within(Unlimited::time())->deadline())->toEqual(Unlimited::time());
});
