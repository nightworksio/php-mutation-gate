<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use Closure;

use function count;
use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
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
 * decision 3). A cluster of survivors is one annotation, at its first member
 * (ADR-0022, decision 17). The step summary lists them all.
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
        $overview = Overview::of($verdict);
        $ranked = Folded::of($overview->survivors(), $verdict->trees()->clusters());
        $order = static fn(JudgedMutant|Cluster $one, JudgedMutant|Cluster $other): int
            => self::rank($other, $overview) <=> self::rank($one, $overview);
        usort($ranked, $order);
        $errors = [];
        $warnings = [];

        foreach ($ranked as $item) {
            $failing = self::isFailing($item, $overview);
            $level = $failing ? 'error' : 'warning';
            $line = $item instanceof Cluster ? $this->clusterCommand($level, $item) : $this->command($level, $item);
            [$errors, $warnings] = $failing
                ? [$this->kept($errors, $line), $warnings]
                : [$errors, $this->kept($warnings, $line)];
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

    /**
     * How far forward a mutant or cluster goes, compared in order: on a
     * changed line, then a security mutant (ADR-0021, decision 21), then in a
     * set that failed; a cluster is each where any member is.
     *
     * @return array{int, int, int} 1 where it is, 0 where it is not
     */
    private static function rank(JudgedMutant|Cluster $item, Overview $overview): array
    {
        return [
            $item->isOnChangedLine() ? 1 : 0,
            self::isAny($item, $overview->isSecurity(...)) ? 1 : 0,
            self::isFailing($item, $overview) ? 1 : 0,
        ];
    }

    private static function isFailing(JudgedMutant|Cluster $item, Overview $overview): bool
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
    private function clusterCommand(string $level, Cluster $cluster): string
    {
        $mutant = $cluster->representative()->mutant();
        $end = $mutant->location()->end();

        return WorkflowCommand::of($level, [
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['endLine' => $end->number()] : [],
            'title' => sprintf('Mutant cluster: %s', ClusterText::size($cluster)),
        ], sprintf(self::CLUSTER_MESSAGE, ClusterText::hint($cluster), $cluster->stub()));
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
                Mutator::short($mutant->mutator()),
            ),
        ], sprintf(self::MESSAGE, $judged->hint()->text(), $judged->reproduce()));
    }
}
