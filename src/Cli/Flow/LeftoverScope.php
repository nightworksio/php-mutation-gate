<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Planning;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\MadeMutants;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * What a shard's run judges: the whole shard, or, in a job of the second
 * wave, one share of the mutants it left at its deadline (ADR-0190), in the
 * shard's units that hold them, making them alone, its result keeping them
 * alone, left where the verdict reads the share's. A share whose run did not
 * judge a mutant it lists says so: the mutant stays as its shard left it,
 * unjudged, and the verdict counts it unfinished.
 *
 * @internal
 */
final readonly class LeftoverScope
{
    private const string UNJUDGED
        = 'Share %d.%d lists %d mutants its run did not judge, the first %s: each stays as shard %d left it, unjudged.';

    private function __construct(private LeftoverShare|NotGiven $share)
    {
    }

    public static function of(LeftoverShare|NotGiven $share): self
    {
        return new self($share);
    }

    /** Whether the run pulls its work as the plan's shards do (ADR-0134): never for a share, which names its shard. */
    public function pulls(Plan $plan): bool
    {
        return $plan->shards()->pulls() && $this->share instanceof NotGiven;
    }

    /** The shard's units the run mutates: for a share, those its mutants' files lie in, a held directory among them. */
    public function shardOf(Shard $shard): Shard
    {
        return $this->share instanceof LeftoverShare
            ? $shard->holding($shard->units()->holdingAny($this->share->files()))
            : $shard;
    }

    /** What the plan learned, its jobs running their own tail where the share's job does (ADR-0303). */
    public function learned(PlanLearned $learned): PlanLearned
    {
        return $this->share instanceof LeftoverShare && $this->share->runsItsTail()
            ? $learned->tailing($learned->tail()->running())
            : $learned;
    }

    /**
     * The search, its run again of doubtful kills held to `shards.confirm` of the time left where the job is a
     * first-wave shard's that stops at its deadline, the wave after taking what it leaves; a share's job, and a
     * job no wave follows, confirm with all the time they have left (ADR-0332).
     */
    public function confirming(KillSearch $search, bool $stopsForAWave, Planning $planning): KillSearch
    {
        return $stopsForAWave && $this->share instanceof NotGiven
            ? $search->confirmingWithin($planning->confirm())
            : $search;
    }

    public function made(): MadeMutants
    {
        return $this->share instanceof LeftoverShare
            ? MadeMutants::only(...$this->share->natives())
            : MadeMutants::every();
    }

    /** What the run came to, its mutants and flaky ones those it judges alone. */
    public function kept(Mutated|CannotJudge $outcome): Mutated|CannotJudge
    {
        return $this->share instanceof LeftoverShare && $outcome instanceof Mutated
            ? $outcome->keeping($this->share->mutants())
            : $outcome;
    }

    /** A warning for each mutant the share lists that its run's outcome lacks; none for a whole shard. */
    public function unjudged(Mutated|CannotJudge $outcome): Warnings
    {
        if (! $this->share instanceof LeftoverShare || $outcome instanceof CannotJudge) {
            return Warnings::none();
        }

        $judged = [];

        foreach ($outcome->result->mutants() as $mutant) {
            $judged[] = $mutant->id();
        }

        $lacking = [...$this->share->mutants()->without(MutantIds::of(...$judged))];

        return $lacking === [] ? Warnings::none() : Warnings::of(Warning::that(sprintf(
            self::UNJUDGED,
            $this->share->shard()->number(),
            $this->share->number(),
            count($lacking),
            $lacking[0]->value(),
            $this->share->shard()->number(),
        )));
    }

    /** Where the run leaves its result in this directory of results. */
    public function resultIn(Path $results, ShardId $shard): Path
    {
        return $this->share instanceof LeftoverShare
            ? LeftoverParts::resultOf($results, $this->share)
            : Workspace::result($results, $shard);
    }
}
