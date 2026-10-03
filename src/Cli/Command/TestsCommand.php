<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Core\Report\TestsReport;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `tests`: the tests report on the console, from the last run in this
 * checkout judged again with the ledgers and the coverage map it left,
 * running and writing nothing (ADR-0014, decision 5). It exits 0 whatever
 * the report finds, since a test can have value mutation cannot see, and 2
 * where there is no last run, or it cannot be read.
 */
final readonly class TestsCommand
{
    public static function command(Composition $composition): Command
    {
        return new Command('tests')
            ->setDescription('Print the tests report from the ledgers and the last run, running nothing')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $verdict = $composed instanceof Composed ? LastRun::verdict($composed) : $composed;

                if (! $verdict instanceof Verdict) {
                    return Failed::because($output, $verdict);
                }

                $output->write(TestsReport::text($verdict), options: OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }
}
