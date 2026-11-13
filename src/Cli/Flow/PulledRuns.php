<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactAccess;
use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactClaims;
use NightWorksIO\MutationGate\Adapter\GitHub\WatchedJob;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PullQueue;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The items a job of a plan whose shards pull their work takes, each run as
 * its shard would be and left where the verdict reads it, until none is
 * left, one leaves the run no way to pass, or the job the run watches failed
 * (ADR-0134, ADR-0135).
 *
 * @internal
 */
final readonly class PulledRuns
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /**
     * What the job left of the items it took.
     *
     * @param Closure(ShardId): (ShardResult|CannotJudge) $ran what an item came to once run as its shard
     */
    public function left(
        Plan $plan,
        ShardId $job,
        Path $results,
        ArtifactAccess|CannotJudge|NotGiven $service,
        Closure $ran,
    ): ShardsLeft|CannotJudge {
        $exchange = HttpExchange::over(HttpClient::create());
        $pulled = PulledItems::of(
            ArtifactClaims::of(
                $service,
                $this->adapters->environment,
                $exchange,
                $this->settings->shards()->pulling()->pull(),
                $plan->digest(),
                $job,
            ),
            PullQueue::of($plan, $job),
            $job,
        );
        $watched = WatchedJob::of($this->adapters->environment, $service, $exchange);

        while ($pulled instanceof PulledItems && ($item = $pulled->next()) instanceof Shard) {
            $pulled = $this->claimed($pulled, $item, $watched, $results, $ran);
        }

        return $pulled instanceof PulledItems ? $pulled->left() : $pulled;
    }

    /**
     * The items once the job claimed this one and ran it where it took it;
     * what it left, where the watched job failed; or why it cannot leave it.
     *
     * @param Closure(ShardId): (ShardResult|CannotJudge) $ran
     */
    private function claimed(
        PulledItems $pulled,
        Shard $item,
        WatchedJob $watched,
        Path $results,
        Closure $ran,
    ): PulledItems|ShardsLeft|CannotJudge {
        $stopped = $watched->stopped();

        if ($stopped !== '') {
            return $pulled->left()->stopped($stopped);
        }

        $claimed = $pulled->claiming($item);

        return $claimed->took() ? $this->ran($claimed, $item, $results, $ran) : $claimed;
    }

    /**
     * The items once the job ran this one and left its result; what it left,
     * where the item leaves the run no way to pass; or why it cannot leave it.
     *
     * @param Closure(ShardId): (ShardResult|CannotJudge) $ran
     */
    private function ran(
        PulledItems $pulled,
        Shard $item,
        Path $results,
        Closure $ran,
    ): PulledItems|ShardsLeft|CannotJudge {
        $result = $ran($item->id());
        $written = ShardLeft::after(
            $result,
            $result instanceof CannotJudge ? $result : $this->adapters->project->write(
                Workspace::pulled($results, $item->id(), $pulled->job()),
                Contents::of(ShardResultFile::encode($result)),
            ),
        );

        if ($written instanceof CannotJudge) {
            return $written;
        }

        $finished = $pulled->finished($item, $written);

        return $result instanceof ShardResult && $result->doomed()->stopped() ? $finished->left() : $finished;
    }
}
