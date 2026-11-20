<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;

use function is_numeric;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\WholeNumber;

/**
 * When a shard's job stops to keep what it judged (ADR-0114, ADR-0196): the
 * sooner of its budget and its stop, which is its job's target less the
 * setup before the gate started, the wind-down a gate takes once it stops
 * and `shards.margin`, no later than `shards.jobLimit` after the gate
 * started where the config caps it. The setup is measured where the job
 * says when it began, in `MUTATION_GATE_JOB_STARTED`, as seconds since the
 * epoch; otherwise it is the longest the plan learned of recent jobs, or
 * `shards.setup`. The wind-down is the longest the plan learned, or a
 * standard one. Where the plan's jobs run their own tail, the stop is the
 * job's limit less the same, or `shards.jobLimit` (ADR-0303). A budget also
 * starts nothing its estimate says will not fit; the stop alone starts
 * every unit and stops the runner.
 *
 * @internal
 */
final readonly class ShardDeadline
{
    /** The variable a CI job names the instant it began in, as seconds since the epoch. */
    public const string JOB_STARTED = 'MUTATION_GATE_JOB_STARTED';

    /** The variable a CI hands the plan's job the minutes each shard's job may run in. */
    public const string JOB_LIMIT = 'MUTATION_GATE_JOB_LIMIT';

    private function __construct(private Deadline $deadline, private Seconds|NotGiven $setup)
    {
    }

    /** How long the job took to start its gate; none where the job did not say when it began. */
    public function setup(): Seconds|NotGiven
    {
        return $this->setup;
    }

    /**
     * The deadline of a shard whose gate starts at this instant, in a job
     * this environment describes, of a plan that learned and decided this.
     */
    public static function of(
        Settings $settings,
        PlanLearned $learned,
        Variables $environment,
        DateTimeImmutable $now,
    ): self {
        $shards = $settings->shards();
        $setup = self::setupOf($environment, $now, $shards);
        $stop = $learned->stop($shards, $setup);
        $budget = $settings->triage()->budget();
        $sooner = $budget instanceof Seconds && $stop->outlasts($budget) ? $budget : $stop;

        return new self(Deadline::after($now, $sooner), $setup);
    }

    /**
     * The longest a shard's job may run, its setup included, as the CI hands
     * it in whole minutes; GitHub's six hours where it hands none.
     */
    public static function limitOf(Variables $environment): Seconds
    {
        $minutes = $environment->valueOf(self::JOB_LIMIT);

        return WholeNumber::isPositive($minutes)
            ? Seconds::minutes((int) $minutes)
            : Seconds::of(JobTail::LIMIT_SECONDS);
    }

    /** What this deadline's estimates must fit: it, under a budget; nothing under the stop alone. */
    public static function fittedTo(Settings $settings, Deadline|Unlimited $deadline): Deadline|Unlimited
    {
        return $settings->triage()->budget() instanceof Seconds ? $deadline : Unlimited::time();
    }

    public function deadline(): Deadline
    {
        return $this->deadline;
    }

    /**
     * How long the job took before its gate started at this instant: since
     * the instant the job named, no less than none and no more than its
     * target; none where it named none.
     */
    private static function setupOf(Variables $environment, DateTimeImmutable $now, Shards $shards): Seconds|NotGiven
    {
        $started = $environment->valueOf(self::JOB_STARTED);

        if (! is_numeric($started)) {
            return NotGiven::value();
        }

        $since = (float) $now->format('U.u') - (float) $started;

        return Seconds::of(min(max(0.0, $since), $shards->stop()->target()->seconds()));
    }
}
