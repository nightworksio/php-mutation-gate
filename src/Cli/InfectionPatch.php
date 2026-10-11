<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function is_string;

use NightWorksIO\MutationGate\Adapter\Infection\Patch;
use NightWorksIO\MutationGate\Adapter\Infection\UnsupportedRelease;
use NightWorksIO\MutationGate\Cli\Command\Aside;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `infection:patch`, run from a project's `post-install-cmd` and
 * `post-update-cmd` where the runner is Infection, and by the GitHub action.
 * It exits 1 for a release it does not patch, which runs with Infection's own
 * limit, and 2 where it cannot patch a release it supports; either fails an
 * install, and the action warns of the first and fails on the second.
 */
final readonly class InfectionPatch
{
    private const string DESCRIPTION = 'Give Infection the gate\'s mutant limit';

    public static function command(string $vendor): Command
    {
        return new Command(Patch::COMMAND)
            ->setDescription(self::DESCRIPTION)
            ->setCode(static function (OutputInterface $output) use ($vendor): int {
                $patched = Patch::applyIn($vendor);

                if (! is_string($patched)) {
                    Aside::of($output)->writeln($patched->why(), OutputInterface::OUTPUT_RAW);

                    return $patched instanceof UnsupportedRelease
                        ? ExitCode::Failed->value
                        : ExitCode::CannotJudge->value;
                }

                $output->writeln($patched, OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }
}
