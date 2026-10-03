<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Doctor\Measure;
use NightWorksIO\MutationGate\Cli\Doctor\Observed;
use NightWorksIO\MutationGate\Cli\Doctor\Online;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Diagnosis;
use NightWorksIO\MutationGate\Core\Doctor\DoctorJson;
use NightWorksIO\MutationGate\Core\Doctor\DoctorText;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Report\Form;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `doctor`: what would fail, or run slowly, before a run does (ADR-0017,
 * decision 9). It reads and never writes, runs none of the project's code
 * unless `--measure` asks it to run the suite once, reads GitHub only when
 * `--online` asks it to, and exits 1 when anything would fail a run.
 */
final readonly class Doctor
{
    private const string NAME = 'doctor';

    public static function command(Observed $observed, Measure $measure, Online $online, Guide $guide): Command
    {
        return FormOption::on(new Command(self::NAME))
            ->setDescription('Say what would fail, or run slowly, before a run does')
            ->addOption(
                'measure',
                mode: InputOption::VALUE_NONE,
                description: 'Also run the suite under coverage: a green suite, a working driver, the hot paths',
            )
            ->addOption(
                'online',
                mode: InputOption::VALUE_NONE,
                description: 'Also read GitHub\'s settings: the required verdict, fork approval, the schedule',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $observed,
                $measure,
                $online,
                $guide,
            ): int {
                $format = FormOption::asked($input, self::NAME);

                if ($format instanceof CannotJudge) {
                    return Failed::because($output, $format);
                }

                $findings = Diagnosis::of(self::observed($input, $observed, $measure, $online));
                $output->writeln(
                    $format === Form::Json ? DoctorJson::of($findings, $guide) : DoctorText::of($findings, $guide),
                    OutputInterface::OUTPUT_RAW,
                );

                return $findings->failARun() ? ExitCode::Failed->value : ExitCode::Passed->value;
            });
    }

    /** What doctor observes, with what `--measure` and `--online` add where they are given. */
    private static function observed(
        InputInterface $input,
        Observed $observed,
        Measure $measure,
        Online $online,
    ): Observations {
        $observations = $observed->of(CommandLine::from($input));
        $observations = $input->getOption('measure') === true ? $measure->into($observations, $input) : $observations;

        return $input->getOption('online') === true ? $online->into($observations) : $observations;
    }
}
