<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Digesting;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\DigestRows;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\Digest\SurvivorsShown;
use NightWorksIO\MutationGate\Core\Verb;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `digest [--detail=<file|id|legend>] [--survivors=<n|all>] [--tests=<n>]`:
 * the last run's verdict for an agent or a terminal, one fact a line,
 * rendered from the report the verdict kept, running nothing (ADR-0009).
 * `--tests` names as many of a mutant's covering tests, in place of
 * `digest.tests`. It exits 0 once it has said it, and 2 where no run left a
 * report, the report cannot be read, a count is no whole number, or the
 * detail names nothing the run holds.
 *
 * @internal
 */
final readonly class DigestCommand
{
    private const string NAME = Verb::Digest->value;

    private const string DETAIL = 'detail';

    private const string SURVIVORS = 'survivors';

    private const string TESTS = 'tests';

    private const string NOT_A_COUNT = '--tests is %s; it takes a whole number.';

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
            ->addOption(
                self::TESTS,
                mode: InputOption::VALUE_REQUIRED,
                description: 'How many of a mutant\'s covering tests its detail names, in place of digest.tests',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $detail = $input->getOption(self::DETAIL);
                $survivors = $input->getOption(self::SURVIVORS);
                $shown = is_string($survivors) ? SurvivorsShown::asked($survivors) : SurvivorsShown::first();
                $tests = self::tests($input, $composed);

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    $shown instanceof CannotJudge => Failed::because($output, $shown),
                    $tests instanceof CannotJudge => Failed::because($output, $tests),
                    default => self::said(
                        $output,
                        Digesting::of(
                            $composed->adapters->project,
                            is_string($detail) ? $detail : NotGiven::value(),
                            $shown,
                            $composed->setup->command,
                            $tests,
                        ),
                    ),
                };
            });
    }

    /** How many of a mutant's tests `--tests` asks for, or `digest.tests` where it asks for none. */
    private static function tests(InputInterface $input, Composed|Invalid|CannotJudge $composed): int|CannotJudge
    {
        $asked = $input->getOption(self::TESTS);
        $configured = $composed instanceof Composed
            ? $composed->settings->effective()->output()->reports()->digest()->tests()
            : DigestRows::TESTS;

        return match (true) {
            ! is_string($asked) => $configured,
            WholeNumber::isDigits($asked) => (int) $asked,
            default => CannotJudge::because(sprintf(self::NOT_A_COUNT, Visible::line($asked))),
        };
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
