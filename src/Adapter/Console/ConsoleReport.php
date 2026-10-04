<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reason as Cause;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\RisingFloors;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Report\TestsText;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The reporter `console`, which every run has: the verdict, every tree,
 * new-code set and package's security set, the units and where their
 * results came from, what the change reached and why, every mutant the score
 * counts as not killed with its diff, hint, judging tests, reproduce and
 * explain commands, and each cluster of them once with its members' diffs
 * and stub command, the ignored mutants with their reasons, the mutants
 * proven equivalent, the floors that can rise, the failures and warnings,
 * and last what the run took and saved.
 */
final readonly class ConsoleReport implements Configurable, Reporter
{
    private const string RAISE = 'Raise them with vendor/bin/mutation-gate baseline --write, and commit the baseline.';

    /** A floor that can rise: what it is of, and what to. */
    private const string RISES = '%s to %s';

    private function __construct(private OutputInterface $output)
    {
    }

    public static function to(OutputInterface $output): self
    {
        return new self($output);
    }

    public static function fromOptions(Options $options): self
    {
        return new self(new InertOutput());
    }

    public function report(Verdict $verdict): Written
    {
        $overview = Overview::of($verdict);
        $lines = [
            sprintf('mutation-gate: %s', $verdict->judgement()->value),
            ...$verdict->wasCutShort() ? ['The run\'s budget stopped it before every mutant was judged.'] : [],
            SetText::project($overview->score()),
            ...$this->section('Cannot judge', $this->obstacles($verdict)),
            ...$this->section('Trees', $this->trees($verdict)),
            ...$this->section('New code', $this->newCode($verdict)),
            ...$this->section('Security', $this->security($verdict)),
            ...$this->section('Units', $this->units($verdict)),
            ...$this->section('Reach', $this->texts($verdict->reach())),
            ...$this->section(
                sprintf('Not killed (%d)', count($overview->survivors())),
                $this->survivors($verdict, $overview),
            ),
            ...$this->section('Ignored', $this->leftOut($overview->ignored())),
            ...$this->section('Equivalent, proven', $this->leftOut($overview->equivalent())),
            ...$this->section('Floors that can rise', $this->raised($verdict)),
            ...$this->section('Failures', $this->texts($verdict->failures())),
            ...$this->section('Warnings', $this->texts($verdict->warnings())),
            ...$this->headline($verdict),
        ];

        foreach ($lines as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Written::toTheConsole();
    }

    /**
     * A heading and its lines, indented, after a blank line; nothing where there are no lines.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    private function section(string $heading, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $indented = [];

        foreach ($lines as $line) {
            foreach (explode("\n", $line) as $part) {
                $indented[] = $part === '' ? '' : sprintf('%s%s', TestsText::INDENT, $part);
            }
        }

        return ['', $heading, ...$indented];
    }

    /** @return list<string> */
    private function trees(Verdict $verdict): array
    {
        $lines = [];

        foreach ($verdict->trees() as $tree) {
            $lines[] = SetText::tree($tree);
        }

        return $lines;
    }

    /** @return list<string> */
    private function newCode(Verdict $verdict): array
    {
        $lines = [];

        foreach ($verdict->sets()->newCode() as $set) {
            $lines[] = SetText::newCode($set);
        }

        return $lines;
    }

    /** @return list<string> */
    private function security(Verdict $verdict): array
    {
        $lines = [];

        foreach ($verdict->sets()->security() as $set) {
            $lines[] = SetText::security($set);
        }

        return $lines;
    }

    /** @return list<string> */
    private function units(Verdict $verdict): array
    {
        $units = $verdict->trees()->units();
        $from = [];

        foreach (Origin::cases() as $origin) {
            $from[$origin->value] = 0;
        }

        foreach ($units as $unit) {
            ++$from[$unit->origin()->value];
        }

        return count($units) === 0 ? [] : [sprintf(
            '%d: %d run, %d proved, %d carried.',
            count($units),
            $from[Origin::Run->value],
            $from[Origin::Proved->value],
            $from[Origin::Carried->value],
        )];
    }

    /** @return list<string> */
    private function survivors(Verdict $verdict, Overview $overview): array
    {
        $blocks = [];

        foreach (Folded::of($overview->survivors(), $verdict->trees()->clusters()) as $item) {
            $blocks[] = $item instanceof Cluster
                ? ClusterText::block($item)
                : MutantText::block($item, $verdict->matrix()->names());
        }

        return $blocks === [] ? [] : [implode("\n\n", $blocks)];
    }

    /**
     * These mutants, each with the reason its record gives.
     *
     * @param  list<JudgedMutant> $mutants
     * @return list<string>
     */
    private function leftOut(array $mutants): array
    {
        $lines = [];

        foreach ($mutants as $judged) {
            $reason = $judged->mutant()->reason();
            $lines[] = $reason instanceof Reason
                ? sprintf('%s: %s', MutantText::heading($judged), $reason->text())
                : MutantText::heading($judged);
        }

        return $lines;
    }

    /** @return list<string> */
    private function raised(Verdict $verdict): array
    {
        $lines = [];

        foreach (RisingFloors::of($verdict) as [$named, $raised]) {
            $lines[] = sprintf(self::RISES, $named, Percent::of($raised));
        }

        return $lines === [] ? [] : [...$lines, self::RAISE];
    }

    /**
     * What the run took and saved, last, after a blank line; nothing for an untimed run.
     *
     * @return list<string>
     */
    private function headline(Verdict $verdict): array
    {
        $headline = SavingsText::of($verdict, NoHistory::yet());

        return $headline === '' ? [] : ['', $headline];
    }

    /**
     * Why the run could not judge, one reason to a line.
     *
     * @return list<string>
     */
    private function obstacles(Verdict $verdict): array
    {
        $whys = [];

        foreach ($verdict->obstacles() as $obstacle) {
            $whys[] = $obstacle->why();
        }

        return $whys;
    }

    /**
     * @param  iterable<Cause|Failure|Warning> $items
     * @return list<string>
     */
    private function texts(iterable $items): array
    {
        $texts = [];

        foreach ($items as $item) {
            $texts[] = $item->text();
        }

        return $texts;
    }
}
