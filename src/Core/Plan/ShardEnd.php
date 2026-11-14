<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * How the CI job of a shard that left no result ended: how, the job's
 * address where the CI gave one, and for a cancelled job, the shard whose
 * failure the CI cancelled it after, where one failed before it.
 *
 * @internal
 */
final readonly class ShardEnd
{
    private const string SHUTDOWN = 'its runner received a shutdown signal, which stopped the job';

    private const string LOST = 'its runner lost contact with the CI, as a runner starved of memory or CPU does';

    private const string CANCELLED_AFTER
        = 'the CI cancelled it once shard %d failed, as fail-fast cancels the other shards';

    private const string CANCELLED = 'the CI cancelled it';

    private const string TIMED_OUT = 'its job ran past its time limit';

    private const string FAILED = 'its job failed before the shard wrote a result';

    private const string SEE = '%s: %s';

    private function __construct(
        private JobEnding $how,
        private string|NotGiven $job,
        private ShardId|NotGiven $after,
    ) {
    }

    public static function of(JobEnding $how, string|NotGiven $job, ShardId|NotGiven $after): self
    {
        return new self($how, $job, $after);
    }

    public function how(): JobEnding
    {
        return $this->how;
    }

    /** What ended the job, and where the CI shows it. */
    public function why(): string
    {
        $why = match ($this->how) {
            JobEnding::Shutdown => self::SHUTDOWN,
            JobEnding::Lost => self::LOST,
            JobEnding::Cancelled => $this->after instanceof ShardId
                ? sprintf(self::CANCELLED_AFTER, $this->after->number())
                : self::CANCELLED,
            JobEnding::TimedOut => self::TIMED_OUT,
            JobEnding::Failed => self::FAILED,
        };

        return $this->job instanceof NotGiven ? $why : sprintf(self::SEE, $why, $this->job);
    }
}
