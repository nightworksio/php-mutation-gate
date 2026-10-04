<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;
use function intval;
use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Triaging;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\TriageText;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Triage\Repeated;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `triage <path>`: one unit run n times, 5 by default, and every mutant whose
 * status varied listed (ADR-0008, decision 3), each run said on standard
 * error as it ends. It exits 1 where a mutant varied, 0 where none did, and
 * 2 where the unit cannot be run.
 */
final readonly class TriageCommand
{
    private const string REPEAT = 'repeat';

    private const string ORDER = 'order';

    /** The runs a triage makes unless asked for another number. */
    private const string RUNS = '5';

    /** The fewest runs that can disagree. */
    private const int FEWEST = 2;

    private const string REPEAT_WHAT = '--repeat takes a whole number of 2 or more, not "%s".';

    private const string ORDER_WHAT = '--order takes runner or killers-first, not "%s".';

    private const string RAN = 'Run %d of %d made %d %s.';

    public static function command(Composition $composition): Command
    {
        return new Command('triage')
            ->setDescription('Run a unit n times and list every mutant whose result varied')
            ->addArgument('path', InputArgument::REQUIRED, 'The unit: a file of a tree, or a held path')
            ->addOption(
                self::REPEAT,
                mode: InputOption::VALUE_REQUIRED,
                description: 'How many times to run the unit, 2 or more',
                default: self::RUNS,
            )
            ->addOption(
                self::ORDER,
                mode: InputOption::VALUE_REQUIRED,
                description: 'The order of each mutant\'s tests: runner or killers-first, tests.order by default',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $runs = self::runs($input);
                $composed = $runs instanceof CannotJudge ? $runs : $composition->compose($input);
                $order = $composed instanceof Composed ? self::order($input, $composed) : $composed;

                return match (true) {
                    $runs instanceof CannotJudge => Failed::because($output, $runs),
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    ! $order instanceof TestOrder => Failed::because($output, $order),
                    default => self::triaged($composed, $input, $output, $runs, $order),
                };
            });
    }

    /** @param int<2, max> $runs */
    private static function triaged(
        Composed $composed,
        InputInterface $input,
        OutputInterface $output,
        int $runs,
        TestOrder $order,
    ): int {
        $path = $input->getArgument('path');
        $aside = Aside::of($output);
        $triaged = new Triaging($composed->adapters, $composed->settings)->triaged(
            Path::of(is_string($path) ? $path : ''),
            $runs,
            $order,
            static function (int $run, MutationResult $result) use ($aside, $runs): void {
                $made = count($result->mutants());
                $ran = sprintf(self::RAN, $run, $runs, $made, $made === 1 ? 'mutant' : 'mutants');
                $aside->writeln($ran, OutputInterface::OUTPUT_RAW);
            },
        );

        if (! $triaged instanceof Repeated) {
            return Failed::because($output, $triaged);
        }

        $output->writeln(TriageText::of($triaged), OutputInterface::OUTPUT_RAW);

        return count($triaged->varied()) === 0 ? ExitCode::Passed->value : ExitCode::Failed->value;
    }

    /** @return int<2, max>|CannotJudge */
    private static function runs(InputInterface $input): int|CannotJudge
    {
        $asked = $input->getOption(self::REPEAT);
        $text = is_string($asked) ? $asked : '';
        $runs = WholeNumber::isPositive($text) ? intval($text) : 0;

        return $runs >= self::FEWEST ? $runs : CannotJudge::because(sprintf(self::REPEAT_WHAT, $text));
    }

    private static function order(InputInterface $input, Composed $composed): TestOrder|CannotJudge
    {
        $asked = $input->getOption(self::ORDER);
        $text = is_string($asked) ? $asked : '';
        $order = $text === '' ? $composed->settings->triage()->order() : TestOrder::tryFrom($text);

        return $order instanceof TestOrder ? $order : CannotJudge::because(sprintf(self::ORDER_WHAT, $text));
    }
}
