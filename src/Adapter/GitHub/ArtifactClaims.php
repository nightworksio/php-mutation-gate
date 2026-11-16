<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_all;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\ItemClaims;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\ShardPull;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\StoredZip;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;
use function str_starts_with;

/**
 * Claims and done markers as artifacts of the workflow run, named for the
 * plan, the run's attempt and the item, written and listed through the
 * artifact service with the job's runtime token (ADR-0134). A name the
 * service refuses as taken is another job's claim.
 *
 * @internal
 */
final readonly class ArtifactClaims implements ItemClaims
{
    private const string CLAIM = 'mutation-gate-claim-%s-%d-%d';

    private const string DONE = 'mutation-gate-done-%s-%d-%d-%d';

    private const string DONE_OF = 'mutation-gate-done-%s-%d-%d-';

    private const string UNHANDED = 'The gate was handed no artifact service to claim items by.';

    private function __construct(
        private RunArtifacts $artifacts,
        private ShardPull $pull,
        private string $plan,
        private int $attempt,
        private ShardId $shard,
    ) {
    }

    /**
     * Claims for this job of the plan, pulling as `shards.pull` says on the
     * service the gate was handed; or why it cannot reach them.
     */
    public static function of(
        ArtifactAccess|CannotJudge|NotGiven $service,
        Variables $environment,
        Exchange $exchange,
        ShardPull $pull,
        Digest $plan,
        ShardId $shard,
    ): self|CannotJudge {
        if (! $service instanceof ArtifactAccess) {
            return $service instanceof CannotJudge ? $service : CannotJudge::because(self::UNHANDED);
        }

        $artifacts = RunArtifacts::of($service, $exchange);

        return $artifacts instanceof CannotJudge ? $artifacts : new self(
            $artifacts,
            $pull->resolved(),
            $plan->value(),
            RunArtifacts::attempt($environment),
            $shard,
        );
    }

    /** The way this job pulls: `auto` resolved for its service. */
    public function pull(): ShardPull
    {
        return $this->pull;
    }

    public function take(ShardId $item): bool|CannotJudge
    {
        return $this->pull === ShardPull::Claim
            ? $this->created(sprintf(self::CLAIM, $this->plan, $this->attempt, $item->number()))
            : $this->unfinished($item);
    }

    public function finish(ShardId $item): ShardId|CannotJudge
    {
        $marked = $this->pull === ShardPull::Claim ? true : $this->created(sprintf(
            self::DONE,
            $this->plan,
            $this->attempt,
            $item->number(),
            $this->shard->number(),
        ));

        return $marked instanceof CannotJudge ? $marked : $item;
    }

    /** Whether no job marked the item done in this attempt. */
    private function unfinished(ShardId $item): bool|CannotJudge
    {
        $names = $this->artifacts->names();

        if ($names instanceof CannotJudge) {
            return $names;
        }

        $done = sprintf(self::DONE_OF, $this->plan, $this->attempt, $item->number());
        return array_all($names, fn(string $name): bool => !str_starts_with($name, $done));
    }

    /** Whether this job created an empty artifact of this name; false where another artifact holds it. */
    private function created(string $name): bool|CannotJudge
    {
        return $this->artifacts->created($name, StoredZip::of([]));
    }
}
