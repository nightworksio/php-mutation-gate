<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command the README promises and this build does not have. It accepts any
 * arguments, says so, and cannot judge.
 */
final readonly class NotBuilt
{
    public static function command(string $name, string $description): Command
    {
        $command = new Command($name)
            ->setDescription($description)
            ->setCode(static function (OutputInterface $output) use ($name): int {
                $output->writeln(sprintf('mutation-gate %s is not built yet, so it cannot judge anything.', $name));

                return ExitCode::CannotJudge->value;
            });
        $command->ignoreValidationErrors();

        return $command;
    }
}
