<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Digesting;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\Digest\SurvivorsShown;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `digest [--detail=<file|id|legend>] [--survivors=<n|all>]`: the last run's
 * verdict for an agent or a terminal, one fact a line, rendered from the
 * report the verdict kept, running nothing (ADR-0009). It exits 0 once it
 * has said it, and 2 where no run left a report, the report cannot be read,
 * or the detail names nothing the run holds.
 */
final readonly class DigestCommand
{
    private const string NAME = BuiltinReporter::Digest->value;

    private const string DETAIL = 'detail';

    private const string SURVIVORS = 'survivors';

    public static function command(Composition $composition): Command
    {
        return new Command(self::NAME)
            ->setDescription('Say the last run\'s verdict one fact a line, for an agent or a terminal, running nothing')
            ->addOption(
                self::DETAIL,
                mode: InputOption::VALUE_REQUIRED,
                description: 'One file, mutant or cluster in depth, or legend for what each word means',
            )
            ->addOption(
                self::SURVIVORS,
                mode: InputOption::VALUE_REQUIRED,
                description: 'How many survivors the overview lists, or all',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $detail = $input->getOption(self::DETAIL);
                $survivors = $input->getOption(self::SURVIVORS);
                $shown = is_string($survivors) ? SurvivorsShown::asked($survivors) : SurvivorsShown::first();

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    $shown instanceof CannotJudge => Failed::because($output, $shown),
                    default => self::said(
                        $output,
                        Digesting::of(
                            $composed->adapters->project,
                            is_string($detail) ? $detail : NotGiven::value(),
                            $shown,
                        ),
                    ),
                };
            });
    }

    private static function said(OutputInterface $output, string|CannotJudge $digest): int
    {
        if ($digest instanceof CannotJudge) {
            return Failed::because($output, $digest);
        }

        $output->write($digest, options: OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
