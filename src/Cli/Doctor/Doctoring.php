<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Init\Asking;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Diagnosis;
use NightWorksIO\MutationGate\Core\Doctor\DoctorText;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Fix\FixText;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What `doctor` observes and finds, with what `--measure` and `--online`
 * add; and `doctor --fix` (ADR-0083): every diff and what it cannot fix,
 * one question unless `--yes` answers it, nothing written where nobody can
 * answer, and, once it wrote, the project looked at again.
 *
 * @internal
 */
final readonly class Doctoring
{
    public const string MEASURE = 'measure';

    public const string ONLINE = 'online';

    public const string YES = 'yes';

    public function __construct(
        private Observed $observed,
        private Measure $measure,
        private Online $online,
        private Fixing $fixing,
        private Guide $guide,
    ) {
    }

    /** What doctor observes, with what `--measure` and `--online` add where they are given. */
    public function observed(InputInterface $input): Observations
    {
        $observations = $this->observed->of(CommandLine::from($input));
        $observations = $input->getOption(self::MEASURE) === true
            ? $this->measure->into($observations, $input)
            : $observations;

        return $input->getOption(self::ONLINE) === true ? $this->online->into($observations) : $observations;
    }

    /** The findings once `--fix` ran on these observations, printed where it wrote; or why it could not write. */
    public function fixed(
        InputInterface $input,
        OutputInterface $output,
        Asking $asking,
        Observations $observations,
    ): Findings|CannotJudge {
        $findings = Diagnosis::of($observations);
        $repairs = $this->fixing->repairs($findings, $observations);
        $edits = $this->fixing->edits($repairs);
        $output->writeln(['', FixText::preview($repairs, $edits)], OutputInterface::OUTPUT_RAW);

        if ($edits === []) {
            return $findings;
        }

        if (! $this->applies($input, $asking, FixText::question($repairs, $edits))) {
            $output->writeln($asking->isInteractive() ? '' : FixText::UNWRITTEN, OutputInterface::OUTPUT_RAW);

            return $findings;
        }

        $written = $this->fixing->write(...$edits);

        return $written instanceof CannotJudge ? $written : $this->lookedAgain($input, $output, $written);
    }

    /** The exit code doctor leaves for these findings. */
    public static function exitFor(Findings $findings): int
    {
        return $findings->failARun() ? ExitCode::Failed->value : ExitCode::Passed->value;
    }

    /** Whether the edits are to be written: `--yes` says so, or someone answers the question yes. */
    private function applies(InputInterface $input, Asking $asking, string $question): bool
    {
        return $input->getOption(self::YES) === true
            || ($asking->isInteractive() && $asking->confirms($question, default: false));
    }

    /** The findings once doctor looked again, after it wrote this many files, printed. */
    private function lookedAgain(InputInterface $input, OutputInterface $output, int $written): Findings
    {
        $fixed = Diagnosis::of($this->observed($input));
        $output->writeln(
            ['', FixText::wrote($written), '', DoctorText::of($fixed, $this->guide)],
            OutputInterface::OUTPUT_RAW,
        );

        return $fixed;
    }
}
