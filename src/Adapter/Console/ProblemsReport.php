<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use function array_key_exists;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The reporter `problems`, which `--output=problems` prints in place of the
 * console's table: one line per result for an editor, then the line that
 * ends the judgement. `judging()` prints the line that begins it, before
 * the run (ADR-0015, decision 6).
 */
final readonly class ProblemsReport implements Configurable, Reporter
{
    private const string ONLY = 'The problems output shows every result, or only those on changed lines: "changed".';

    private function __construct(
        private OutputInterface $output,
        private Root $project,
        private ProblemsShown $shown,
    ) {
    }

    /** Problems printed to this output, with the mutated files read under this directory. */
    public static function to(OutputInterface $output, Root $project, ProblemsShown $shown): self
    {
        return new self($output, $project, $shown);
    }

    /** To the console, from where the gate runs; `only: "changed"` shows the results on changed lines alone. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $only = $options->text(Key::of('only'));
        $named = match (true) {
            $only instanceof NotGiven => ProblemsShown::All->value,
            is_string($only) => $only,
            default => '',
        };

        return match ($named) {
            ProblemsShown::All->value => new self(new InertOutput(), Root::here(), ProblemsShown::All),
            ProblemsShown::Changed->value => new self(new InertOutput(), Root::here(), ProblemsShown::Changed),
            default => Invalid::because(Problem::at('only', self::ONLY)),
        };
    }

    /** Say a judgement begins, so a background matcher clears what the last one showed. */
    public function judging(): void
    {
        $this->output->writeln(Problems::JUDGING, OutputInterface::OUTPUT_RAW);
    }

    public function report(Verdict $verdict): Written
    {
        $problems = Problems::text($verdict, $this->sources($verdict), $this->shown);

        $this->output->write($problems, options: OutputInterface::OUTPUT_RAW);
        $this->output->writeln(Problems::JUDGED, OutputInterface::OUTPUT_RAW);

        return Written::toTheConsole();
    }

    /** @return array<string, Contents> each mutated file that can be read, by its path */
    private function sources(Verdict $verdict): array
    {
        $sources = [];

        foreach ($verdict->trees()->mutants() as $judged) {
            $path = $judged->mutant()->location()->file()->value();
            $file = $this->project->at($judged->mutant()->location()->file())->value();
            $text = array_key_exists($path, $sources) || ! is_file($file) ? false : file_get_contents($file);

            if ($text !== false) {
                $sources[$path] = Contents::of($text);
            }
        }

        return $sources;
    }
}
