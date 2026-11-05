<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\Doctor\Doctoring;
use NightWorksIO\MutationGate\Cli\Init\Asking;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Doctor\Diagnosis;
use NightWorksIO\MutationGate\Core\Doctor\DoctorJson;
use NightWorksIO\MutationGate\Core\Doctor\DoctorText;
use NightWorksIO\MutationGate\Core\Report\Form;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Verb;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `doctor`: what would fail, or run slowly, before a run does (ADR-0017).
 * It runs none of the project's code unless `--measure` asks it to run the
 * suite once, and reads GitHub only when `--online` asks it to. It writes
 * nothing unless `--fix` asks it to fix what it can: it shows every diff,
 * asks once unless `--yes` answers, writes nothing where nobody can answer,
 * and once it wrote, looks again (ADR-0083). It exits 1 when anything would
 * fail a run.
 *
 * @internal
 */
final readonly class Doctor
{
    private const string NAME = Verb::Doctor->value;

    private const string FIX = 'fix';

    private const string JSON_FIX = '--fix shows diffs and asks, so it writes text: leave out --format=json.';

    public static function command(Doctoring $doctoring, Guide $guide, Variables $environment): Command
    {
        return FormOption::on(new Command(self::NAME))
            ->setDescription('Say what would fail, or run slowly, before a run does')
            ->addOption(
                Doctoring::MEASURE,
                mode: InputOption::VALUE_NONE,
                description: 'Also run the suite under coverage: a green suite, a working driver, the hot paths',
            )
            ->addOption(
                Doctoring::ONLINE,
                mode: InputOption::VALUE_NONE,
                description: 'Also read GitHub\'s settings: the required verdict, fork approval, the schedule',
            )
            ->addOption(self::FIX, mode: InputOption::VALUE_NONE, description: 'Show what it can fix, and fix it')
            ->addOption(Doctoring::YES, mode: InputOption::VALUE_NONE, description: 'With --fix, fix without asking')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $doctoring,
                $guide,
                $environment,
            ): int {
                $format = self::asked($input);

                if ($format instanceof CannotJudge) {
                    return Failed::because($output, $format);
                }

                $observations = $doctoring->observed($input);
                $findings = Diagnosis::of($observations);
                $output->writeln(
                    $format === Form::Json ? DoctorJson::of($findings, $guide) : DoctorText::of($findings, $guide),
                    OutputInterface::OUTPUT_RAW,
                );
                $found = $input->getOption(self::FIX) === true
                    ? $doctoring->fixed($input, $output, Asking::at($input, $output, $environment), $observations)
                    : $findings;

                return $found instanceof CannotJudge ? Failed::because($output, $found) : Doctoring::exitFor($found);
            });
    }

    /** The form asked for; or why doctor refuses it, as `--fix` refuses JSON, since it shows diffs and asks. */
    private static function asked(InputInterface $input): Form|CannotJudge
    {
        $format = FormOption::asked($input, self::NAME);

        return $format === Form::Json && $input->getOption(self::FIX) === true
            ? CannotJudge::because(self::JSON_FIX)
            : $format;
    }
}
