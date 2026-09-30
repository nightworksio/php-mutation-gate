<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function count;
use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;
use function usort;

/**
 * The reporter `github-annotations`: line annotations as workflow commands,
 * which need no permission. A mutant the score counts as not killed is an
 * error in a set that failed and a warning elsewhere; each warning of the
 * verdict is a notice. GitHub keeps 10 of each level per step and 50 per job
 * and drops the rest silently, so the gate writes at most 10 of each from its
 * one step, ranked: changed lines first, then sets that failed (ADR-0009,
 * decision 3). The step summary lists them all.
 */
final readonly class Annotations implements Configurable, Reporter
{
    /** The most annotations of one level GitHub keeps from a step. */
    private const int PER_LEVEL = 10;

    private const string MESSAGE = '%s Reproduce: %s';

    private function __construct(private string $to)
    {
    }

    /** Annotations printed to a stream or appended to a file. */
    public static function printingTo(string $to): self
    {
        return new self($to);
    }

    public static function fromOptions(Options $options): self
    {
        return new self('php://stdout');
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $overview = Overview::of($verdict);
        $ranked = [];

        foreach ($overview->survivors() as $mutant) {
            $ranked[] = $mutant;
        }

        usort($ranked, static fn(JudgedMutant $one, JudgedMutant $other): int => self::rank($other, $overview)
            <=> self::rank($one, $overview));
        $errors = [];
        $warnings = [];

        foreach ($ranked as $mutant) {
            [$errors, $warnings] = $overview->isFailing($mutant)
                ? [$this->kept($errors, $this->command('error', $mutant)), $warnings]
                : [$errors, $this->kept($warnings, $this->command('warning', $mutant))];
        }

        $notices = [];

        foreach ($verdict->warnings() as $warning) {
            $notice = WorkflowCommand::of('notice', ['title' => 'mutation-gate'], $warning->text());
            $notices = $this->kept($notices, $notice);
        }

        $lines = [...$errors, ...$warnings, ...$notices];

        $text = sprintf("%s\n", implode("\n", $lines));

        return $lines === [] || file_put_contents($this->to, $text, FILE_APPEND) !== false
            ? Written::to($this->to)
            : NotWritten::because(sprintf('The annotations could not be written to %s.', $this->to));
    }

    /** How far forward a mutant goes: on a changed line counts most, then in a set that failed. */
    private static function rank(JudgedMutant $mutant, Overview $overview): int
    {
        return ($mutant->isOnChangedLine() ? 2 : 0) + ($overview->isFailing($mutant) ? 1 : 0);
    }

    /**
     * These lines, and one more while there is room for its level.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    private function kept(array $lines, string $line): array
    {
        return count($lines) < self::PER_LEVEL ? [...$lines, $line] : $lines;
    }

    private function command(string $level, JudgedMutant $judged): string
    {
        $mutant = $judged->mutant();
        $end = $mutant->location()->end();

        return WorkflowCommand::of($level, [
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['endLine' => $end->number()] : [],
            'title' => sprintf(
                'Mutant %s: %s',
                Label::of($judged->judgement()),
                Mutator::short($mutant->mutation()->mutator()),
            ),
        ], sprintf(self::MESSAGE, $judged->hint()->text(), $judged->reproduce()));
    }
}
