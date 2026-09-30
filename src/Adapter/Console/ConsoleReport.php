<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reason as Cause;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The reporter `console`, which every run has: the verdict, every tree and
 * new-code set, the units and where their results came from, what the change
 * reached and why, every mutant the score counts as not killed with its diff,
 * hint, judging tests and reproduce command, the ignored mutants with their
 * reasons, the floors that can rise, and the failures and warnings.
 */
final readonly class ConsoleReport implements Configurable, Reporter
{
    private const string INDENT = '  ';

    private const string RAISE = 'Raise them with vendor/bin/mutation-gate baseline --write, and commit the baseline.';

    private function __construct(private OutputInterface $output)
    {
    }

    public static function to(OutputInterface $output): self
    {
        return new self($output);
    }

    public static function fromOptions(Options $options): self
    {
        return new self(new ConsoleOutput());
    }

    public function report(Verdict $verdict): Written
    {
        $overview = Overview::of($verdict);
        $lines = [
            sprintf('mutation-gate: %s', $verdict->judgement()->value),
            ...$verdict->wasCutShort() ? ['The run\'s budget stopped it before every mutant was judged.'] : [],
            SetText::project($overview->score()),
            ...$this->section('Trees', $this->trees($verdict)),
            ...$this->section('New code', $this->newCode($verdict)),
            ...$this->section('Units', $this->units($verdict)),
            ...$this->section('Reach', $this->texts($verdict->reach())),
            ...$this->section(sprintf('Not killed (%d)', count($overview->survivors())), $this->survivors($overview)),
            ...$this->section('Ignored', $this->ignored($verdict)),
            ...$this->section('Floors that can rise', $this->raised($verdict)),
            ...$this->section('Failures', $this->texts($verdict->failures())),
            ...$this->section('Warnings', $this->texts($verdict->warnings())),
        ];

        foreach ($lines as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Written::to('the console');
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
                $indented[] = $part === '' ? '' : sprintf('%s%s', self::INDENT, $part);
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

        foreach ($verdict->newCode() as $set) {
            $lines[] = SetText::newCode($set);
        }

        return $lines;
    }

    /** @return list<string> */
    private function units(Verdict $verdict): array
    {
        $units = $verdict->units();
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
    private function survivors(Overview $overview): array
    {
        $blocks = [];

        foreach ($overview->survivors() as $mutant) {
            $blocks[] = MutantText::block($mutant);
        }

        return $blocks === [] ? [] : [implode("\n\n", $blocks)];
    }

    /** @return list<string> */
    private function ignored(Verdict $verdict): array
    {
        $lines = [];

        foreach ($verdict->mutants() as $judged) {
            $judgement = $judged->judgement();

            if ($judgement !== MutantJudgement::Ignored && $judgement !== MutantJudgement::IgnoredByMarker) {
                continue;
            }

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

        foreach ($verdict->trees() as $tree) {
            $raised = $tree->raised();
            $lines = $raised instanceof Floor
                ? [...$lines, sprintf('%s to %s', $tree->tree()->path()->value(), Percent::of($raised))]
                : $lines;
        }

        return $lines === [] ? [] : [...$lines, self::RAISE];
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
