<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
use NightWorksIO\MutationGate\Adapter\Console\ProblemsReport;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * How a command prints its verdict: the console's report, or, with
 * `--output=problems`, one line per result for an editor's problem matcher,
 * after the line a background matcher waits for before the run; with
 * `--only=changed`, only the results on changed lines (ADR-0015); a quick
 * round's report says it is no verdict (ADR-0146).
 *
 * @internal
 */
final readonly class Printing
{
    private function __construct(
        private VerdictOutput $output,
        private ProblemsShown $shown,
        private bool $quick = false,
    ) {
    }

    public static function of(VerdictOutput $output, ProblemsShown $shown): self
    {
        return new self($output, $shown);
    }

    /** The console's report, as a command with no `--output` prints. */
    public static function console(): self
    {
        return new self(VerdictOutput::Console, ProblemsShown::All);
    }

    /** The same, printing a quick round's report, which says it is no verdict. */
    public function quickly(): self
    {
        return new self($this->output, $this->shown, quick: true);
    }

    /** Say a judgement begins, before the run, where an editor reads what follows. */
    public function begin(OutputInterface $output, Directory $project): void
    {
        if ($this->output === VerdictOutput::Problems) {
            ProblemsReport::to($output, $project->root(), $this->shown)->judging();
        }
    }

    /** Say a line to whoever reads the console's report; problems leave it out, since an editor reads them. */
    public function note(string $line, OutputInterface $output): void
    {
        if ($this->output === VerdictOutput::Console) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }
    }

    /** The verdict, as the console's report or as problems, with the mutated files read from the project. */
    public function verdict(Verdict $verdict, OutputInterface $output, Directory $project): void
    {
        $reporter = $this->output === VerdictOutput::Problems
            ? ProblemsReport::to($output, $project->root(), $this->shown)
            : ($this->quick ? ConsoleReport::quick($output) : ConsoleReport::to($output));
        $reporter->report($verdict);
    }
}
