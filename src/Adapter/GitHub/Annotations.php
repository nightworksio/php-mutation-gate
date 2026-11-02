<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_slice;
use function count;
use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\AnnotationOrder;
use NightWorksIO\MutationGate\Core\Report\AnnotationText;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * The reporter `github-annotations`: line annotations as workflow commands,
 * which need no permission. A mutant the score counts as not killed is an
 * error in a set that failed and a warning elsewhere; each warning of the
 * verdict is a notice. GitHub keeps 10 of each level per step and 50 per job
 * and drops the rest silently, so the gate writes at most 10 of each from its
 * one step, ranked: changed lines first, then sets that failed (ADR-0009). A
 * cluster of survivors is one annotation, at its first member (ADR-0022).
 * The step summary lists them all.
 */
final readonly class Annotations implements Configurable, Reporter
{
    /** The most annotations of one level GitHub keeps from a step. */
    private const int PER_LEVEL = 10;

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
        $lines = [...$this->mutantCommands($verdict), ...$this->notices($verdict)];
        $text = sprintf("%s\n", implode("\n", $lines));

        return $lines === [] || file_put_contents($this->to, $text, FILE_APPEND) !== false
            ? Written::to($this->to)
            : NotWritten::because(sprintf('The annotations could not be written to %s.', $this->to));
    }

    /**
     * An error for each surviving mutant or cluster in a failing set, then a
     * warning for each other; at each level those on a changed line first,
     * then security mutants (ADR-0021), a cluster where any member is.
     *
     * @return list<string>
     */
    private function mutantCommands(Verdict $verdict): array
    {
        $order = AnnotationOrder::of($verdict);
        $gate = Overview::of($verdict)->gate();

        return [
            ...$this->commands('error', $order->failing(), $gate),
            ...$this->commands('warning', $order->other(), $gate),
        ];
    }

    /**
     * A command at this level for each of the first of these, as many as GitHub keeps of a level.
     *
     * @param  list<JudgedMutant|Cluster> $items
     * @return list<string>
     */
    private function commands(string $level, array $items, GateCommand $gate): array
    {
        $commands = [];

        foreach (array_slice($items, 0, self::PER_LEVEL) as $item) {
            $commands[] = $this->command($level, $item, $gate);
        }

        return $commands;
    }

    /**
     * A notice for each warning of the verdict.
     *
     * @return list<string>
     */
    private function notices(Verdict $verdict): array
    {
        $notices = [];

        foreach ($verdict->warnings() as $warning) {
            $notice = WorkflowCommand::of('notice', ['title' => 'mutation-gate'], $warning->text());
            $notices = $this->kept($notices, $notice);
        }

        return $notices;
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

    /** One annotation for a mutant, or for a whole cluster at its first member. */
    private function command(string $level, JudgedMutant|Cluster $item, GateCommand $gate): string
    {
        $mutant = ($item instanceof Cluster ? $item->representative() : $item)->mutant();
        $end = $mutant->location()->end();

        return WorkflowCommand::of($level, [
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['endLine' => $end->number()] : [],
            'title' => AnnotationText::title($item),
        ], AnnotationText::message($item, $gate));
    }
}
