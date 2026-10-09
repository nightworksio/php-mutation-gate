<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateComposer;

use Composer\Plugin\Capability\CommandProvider;

/** The one command the plugin adds: `mutate`. */
final readonly class MutateCommands implements CommandProvider
{
    /** @return list<MutateCommand> */
    public function getCommands(): array
    {
        return [new MutateCommand()];
    }
}
