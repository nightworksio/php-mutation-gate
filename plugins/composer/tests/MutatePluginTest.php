<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\IO\BufferIO;
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

it('changes nothing as Composer activates, deactivates and uninstalls it, and says nothing', function (): void {
    $plugin = new MutatePlugin();
    $composer = new Composer();
    $io = new BufferIO();

    $plugin->activate($composer, $io);
    $plugin->deactivate($composer, $io);
    $plugin->uninstall($composer, $io);

    expect($io->getOutput())->toBe('');
});
