<?php

declare(strict_types=1);

use Composer\Plugin\Capability\CommandProvider;
use NightWorksIO\MutationGateComposer\MutateCommand;
use NightWorksIO\MutationGateComposer\MutateCommands;
use NightWorksIO\MutationGateComposer\MutatePlugin;

it('offers Composer one command provider, whose one command is mutate', function (): void {
    $commands = new MutateCommands()->getCommands();

    expect(new MutatePlugin()->getCapabilities())->toBe([CommandProvider::class => MutateCommands::class])
        ->and($commands)->toHaveCount(1)
        ->and($commands[0])->toBeInstanceOf(MutateCommand::class)
        ->and($commands[0]->getName())->toBe('mutate');
});
