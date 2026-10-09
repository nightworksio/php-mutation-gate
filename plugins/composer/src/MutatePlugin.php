<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateComposer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * The Composer plugin that adds `composer mutate` (ADR-0024, decision 11).
 * It is optional: a non-interactive install that does not allow it skips it
 * quietly, and the gate itself needs no plugin.
 */
final readonly class MutatePlugin implements Capable, PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /** @return array<class-string, class-string> */
    public function getCapabilities(): array
    {
        return [CommandProvider::class => MutateCommands::class];
    }
}
