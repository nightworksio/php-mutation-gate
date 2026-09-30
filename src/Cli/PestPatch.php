<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Cli\Command\Aside;
use NightWorksIO\MutationGate\Core\CannotJudge;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `pest:patch`, run from a project's `post-install-cmd` and `post-update-cmd`
 * when `pest.patch` is on. A patch that cannot be applied fails the install.
 */
final readonly class PestPatch
{
    private const string NAME = 'pest:patch';

    private const string DESCRIPTION = 'Apply the optional Pest patches';

    public static function command(string $vendor): Command
    {
        return new Command(self::NAME)
            ->setDescription(self::DESCRIPTION)
            ->setCode(static function (OutputInterface $output) use ($vendor): int {
                $patched = Patch::applyIn($vendor);

                if ($patched instanceof CannotJudge) {
                    Aside::of($output)->writeln($patched->why(), OutputInterface::OUTPUT_RAW);

                    return ExitCode::CannotJudge->value;
                }

                $output->writeln($patched, OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }
}
