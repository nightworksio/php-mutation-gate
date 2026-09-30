<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;

use function sprintf;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command that cannot go on, says why on the error stream and ends with
 * exit code 2. An invalid config prints every problem, one per line, each at
 * its path.
 */
final readonly class Failed
{
    public static function because(OutputInterface $output, Invalid|CannotJudge $why): int
    {
        $errors = Aside::of($output);

        if ($why instanceof CannotJudge) {
            $errors->writeln($why->why(), OutputInterface::OUTPUT_RAW);

            return ExitCode::CannotJudge->value;
        }

        foreach ($why as $problem) {
            $line = $problem->path() === ''
                ? $problem->message()
                : sprintf('%s: %s', $problem->path(), $problem->message());
            $errors->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return ExitCode::CannotJudge->value;
    }
}
