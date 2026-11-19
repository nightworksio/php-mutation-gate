<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\StoredZip;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/**
 * What a shard says of its progress, left as an artifact of the workflow run
 * named for the plan, the run's attempt, the shard and how many it left
 * before, each one replacing the one before it, so a job lost with its
 * runner still leaves its last word (ADR-0157).
 *
 * @internal
 */
final class ProgressArtifacts
{
    private const string NAME = 'mutation-gate-progress-%s-%d-%d-%d';

    private const string TAKEN = 'Another artifact of the run is named %s.';

    /** The file the artifact holds. */
    private const string FILE = 'progress.txt';

    /** How many artifacts it left so far, the last of which stands. */
    private int $left = 0;

    private function __construct(
        private readonly RunArtifacts $artifacts,
        private readonly string $plan,
        private readonly int $attempt,
        private readonly ShardId $shard,
    ) {
    }

    public static function of(RunArtifacts $artifacts, Digest $plan, int $attempt, ShardId $shard): self
    {
        return new self($artifacts, $plan->value(), $attempt, $shard);
    }

    /** This progress left in a new artifact, and the one before it deleted; or why it was not left. */
    public function leave(string $progress): true|CannotJudge
    {
        $created = $this->artifacts->created($this->named($this->left + 1), StoredZip::of([self::FILE => $progress]));

        if ($created !== true) {
            return $created === false
                ? CannotJudge::because(sprintf(self::TAKEN, $this->named($this->left + 1)))
                : $created;
        }

        $this->left++;

        return $this->left === 1 ? true : $this->artifacts->deleted($this->named($this->left - 1));
    }

    /** The artifact standing deleted, where one stands; or why it was not. */
    public function clear(): true|CannotJudge
    {
        return $this->left === 0 ? true : $this->artifacts->deleted($this->named($this->left));
    }

    private function named(int $number): string
    {
        return sprintf(self::NAME, $this->plan, $this->attempt, $this->shard->number(), $number);
    }
}
