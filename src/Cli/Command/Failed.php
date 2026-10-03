<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;

use function sprintf;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command that cannot go on, says why on the error stream and ends with
 * exit code 2. An invalid config prints every problem, one per line, each at
 * its path. A mutant looked up and not found says why the same way.
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

    /** A command that looked a mutant up and found no record of it, or several, or cannot go on. */
    public static function unfound(OutputInterface $output, NoRecord|Ambiguous|CannotJudge $why): int
    {
        return self::because($output, $why instanceof CannotJudge ? $why : CannotJudge::because($why->why()));
    }
}
