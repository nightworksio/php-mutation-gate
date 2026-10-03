<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Proof\Recorded;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Report\Explanation;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * `explain`: one mutant, or a cluster of survivors, from the ledgers read and
 * the last run in this checkout, running nothing (ADR-0014, decisions 12 to
 * 14). A mutant the last run holds is explained as its verdict judged it; any
 * other by its newest record, judged as a verdict judges it. A cluster is
 * found by the last run alone, since its verdict is what clusters survivors.
 */
final readonly class Explaining
{
    private const string NOT_HELD = 'It is not among the mutants of the last run, so this is its newest record.';

    private const string NO_CLUSTER
        = 'The last run found no cluster %s: clusters change as survivors do, so give one it printed.';

    private const string UNCLUSTERED = 'Clusters are found by the last run, which cannot be read. %s';

    public function __construct(private Composed $composed)
    {
    }

    /** The mutant an id or a prefix names, or the cluster a cluster id names; or why there is none. */
    public function explain(IdPrefix|ClusterId $sought): Explanations|NoRecord|Ambiguous|CannotJudge
    {
        $settings = $this->composed->settings;
        $adapters = $this->composed->adapters;
        $standing = Standing::of($adapters->ci, $adapters->repository, $settings->ci()->defaultBranch());

        if ($standing instanceof CannotJudge) {
            return $standing;
        }

        $ledgers = Ledgers::read($adapters->proofs, $standing, Writing::Never);
        $last = LastRun::verdict($this->composed);

        return $sought instanceof ClusterId
            ? $this->cluster($sought, $ledgers, $last)
            : $this->mutant($sought, $ledgers, $last);
    }

    private function mutant(
        IdPrefix $sought,
        Ledgers $ledgers,
        Verdict|CannotJudge $last,
    ): Explanations|NoRecord|Ambiguous {
        $held = $last instanceof Verdict ? $this->held($sought, $last) : [];
        $history = $ledgers->records($sought);
        $ids = $history->ids();

        foreach ($held as $judged) {
            $ids = $ids->and(MutantIds::of($judged->mutant()->id()));
        }

        $newest = $history->newest();

        return match (true) {
            count($ids) > 1 => Ambiguous::of($sought, $ids),
            $last instanceof Verdict && $held !== []
                => Explanations::ofMutant($this->judged($held[0], $last, $ledgers)),
            $newest instanceof Recorded => Explanations::ofMutant($this->recorded($newest, $history, $last)),
            default => NoRecord::of($sought),
        };
    }

    /** The cluster of the last run this id names, every member explained; or why the last run holds none. */
    private function cluster(ClusterId $sought, Ledgers $ledgers, Verdict|CannotJudge $last): Explanations|CannotJudge
    {
        if (! $last instanceof Verdict) {
            return CannotJudge::because(sprintf(self::UNCLUSTERED, $last->why()));
        }

        $found = Unclustered::mutant();

        foreach ($last->trees()->clusters() as $cluster) {
            $found = $cluster->id()->value() === $sought->value() ? $cluster : $found;
        }

        return $found instanceof Cluster
            ? $this->members($found, $last, $ledgers)
            : CannotJudge::because(sprintf(self::NO_CLUSTER, $sought->value()));
    }

    /** A cluster, its first survivor explained first, then each other member. */
    private function members(Cluster $cluster, Verdict $last, Ledgers $ledgers): Explanations
    {
        $first = $cluster->representative();
        $more = [];

        foreach ($cluster->members() as $member) {
            $more = $member === $first ? $more : [...$more, $this->judged($member, $last, $ledgers)];
        }

        return Explanations::ofCluster($cluster, $this->judged($first, $last, $ledgers), ...$more);
    }

    /** @return list<JudgedMutant|JudgedKill> the mutants of the last run the id or prefix names */
    private function held(IdPrefix $sought, Verdict $last): array
    {
        $held = [];

        foreach ($last->trees()->mutants() as $judged) {
            $held = $sought->names($judged->mutant()->id()) ? [...$held, $judged] : $held;
        }

        return $held;
    }

    private function judged(JudgedMutant|JudgedKill $judged, Verdict $last, Ledgers $ledgers): Explanation
    {
        $id = IdPrefix::of($judged->mutant()->id());

        return Explanation::judged(
            $judged,
            $last->matrix(),
            $last->trees()->units()->holding($judged->mutant()->location()->file()),
            $last->reach(),
            $ledgers->records($id),
        );
    }

    /** Its newest record, judged as a verdict judges a mutant, since the last run does not hold it. */
    private function recorded(Recorded $newest, Records $history, Verdict|CannotJudge $last): Explanation
    {
        $mutant = $newest->mutant();
        $triage = MutantTriage::under($this->composed->settings->triage()->timeouts());

        return Explanation::recorded(
            $mutant instanceof Mutant ? JudgedMutant::of($mutant, $triage->judged($mutant)) : JudgedKill::of($mutant),
            CannotTell::because($last instanceof CannotJudge ? $last->why() : self::NOT_HELD),
            $history,
        );
    }
}
