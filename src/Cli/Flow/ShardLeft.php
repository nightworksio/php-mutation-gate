<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * One shard's result as `run --plan` left it (ADR-0103): where it was written,
 * and why the shard's job fails, where a survivor leaves its run no way to
 * pass, or its runner could not judge it, as a runner that crashed cannot
 * (ADR-0330).
 *
 * @internal
 */
final readonly class ShardLeft
{
    private const string UNJUDGED = "The runner could not judge shard %d in this job, so the job fails:\n%s";

    private function __construct(private ShardResult $result, private Written $written)
    {
    }

    /** What a shard came to and where it was written; or why it has no result, or could not be written. */
    public static function after(ShardResult|CannotJudge $result, Written|CannotJudge $written): self|CannotJudge
    {
        return match (true) {
            $result instanceof CannotJudge => $result,
            $written instanceof CannotJudge => $written,
            default => new self($result, $written),
        };
    }

    /** Where the result was written. */
    public function said(): string
    {
        return $this->written->said();
    }

    /** Why the job fails, where the run cannot pass or the runner could not judge the shard; nothing otherwise. */
    public function doomed(): string
    {
        $doomed = $this->result->doomed();
        $outcome = $this->result->outcome();
        $number = $this->result->shard()->number();

        return match (true) {
            $doomed instanceof Doomed => $doomed->failing($number),
            $outcome instanceof CannotJudge && ! $outcome->isAtTheDeadline() => sprintf(
                self::UNJUDGED,
                $number,
                $outcome->why(),
            ),
            default => '',
        };
    }
}
