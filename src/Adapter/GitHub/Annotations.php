<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;

use Closure;

use function count;
use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Mutator;
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

    private const string MESSAGE = '%s Reproduce: %s';

    private const string CLUSTER_MESSAGE = '%s Stub: %s';

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
        $overview = Overview::of($verdict);
        $secured = $this->forward(
            Folded::of($overview->survivors(), $verdict->trees()->clusters()),
            static fn(JudgedMutant|Cluster $item): bool => self::isAny($item, $overview->isSecurity(...)),
        );
        $ranked = $this->forward($secured, static fn(JudgedMutant|Cluster $item): bool => $item->isOnChangedLine());
        $errors = [];
        $warnings = [];

        foreach ($ranked as $item) {
            $failing = $this->isFailing($item, $overview);
            $level = $failing ? 'error' : 'warning';
            $line = $item instanceof Cluster
                ? $this->clusterCommand($level, $item, $overview->gate())
                : $this->command($level, $item, $overview->gate());
            [$errors, $warnings] = $failing
                ? [$this->kept($errors, $line), $warnings]
                : [$errors, $this->kept($warnings, $line)];
        }

        return [...$errors, ...$warnings];
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
     * These mutants and clusters, those that are so first, each part in the
     * order it had.
     *
     * @param  list<JudgedMutant|Cluster>          $items
     * @param  Closure(JudgedMutant|Cluster): bool $is
     * @return list<JudgedMutant|Cluster>
     */
    private function forward(array $items, Closure $is): array
    {
        return [
            ...array_filter($items, $is),
            ...array_filter($items, static fn(JudgedMutant|Cluster $item): bool => ! $is($item)),
        ];
    }

    private function isFailing(JudgedMutant|Cluster $item, Overview $overview): bool
    {
        return self::isAny($item, $overview->isFailing(...));
    }

    /**
     * Whether a mutant, or any member of a cluster, is so.
     *
     * @param Closure(JudgedMutant): bool $is
     */
    private static function isAny(JudgedMutant|Cluster $item, Closure $is): bool
    {
        foreach ($item instanceof Cluster ? $item->members() : [$item] as $mutant) {
            if ($is($mutant)) {
                return true;
            }
        }

        return false;
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

    /** One annotation for a whole cluster, at its first member. */
    private function clusterCommand(string $level, Cluster $cluster, GateCommand $gate): string
    {
        $mutant = $cluster->representative()->mutant();
        $end = $mutant->location()->end();

        return WorkflowCommand::of($level, [
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['endLine' => $end->number()] : [],
            'title' => sprintf('Mutant cluster: %s', ClusterText::size($cluster)),
        ], sprintf(self::CLUSTER_MESSAGE, ClusterText::hint($cluster), $cluster->stub($gate)));
    }

    private function command(string $level, JudgedMutant $judged, GateCommand $gate): string
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
                Mutator::short($mutant->mutator()),
            ),
        ], sprintf(self::MESSAGE, MutantText::hinted($judged), $judged->reproduce($gate)));
    }
}
