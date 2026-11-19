<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;

use function is_numeric;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\JobStop;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * When a shard's job stops to keep what it judged (ADR-0114, ADR-0196): the
 * sooner of its budget and its stop, which is its job's target less the
 * setup before the gate started, the wind-down a gate takes once it stops
 * and `shards.margin`, no later than `shards.jobLimit` after the gate
 * started where the config caps it. The setup is measured where the job
 * says when it began, in `MUTATION_GATE_JOB_STARTED`, as seconds since the
 * epoch; otherwise it is the longest the plan learned of recent jobs, or
 * `shards.setup`. The wind-down is the longest the plan learned, or a
 * standard one. A budget also starts nothing its estimate says will not
 * fit; the stop alone starts every unit and stops the runner.
 *
 * @internal
 */
final readonly class ShardDeadline
{
    /** The variable a CI job names the instant it began in, as seconds since the epoch. */
    public const string JOB_STARTED = 'MUTATION_GATE_JOB_STARTED';

    private function __construct(private Deadline $deadline, private Seconds|NotGiven $setup)
    {
    }

    /** How long the job took to start its gate; none where the job did not say when it began. */
    public function setup(): Seconds|NotGiven
    {
        return $this->setup;
    }

    /** The deadline of a shard whose gate starts at this instant, in a job this environment describes. */
    public static function of(
        Settings $settings,
        JobsLearned $jobs,
        Variables $environment,
        DateTimeImmutable $now,
    ): self {
        $shards = $settings->shards();
        $setup = self::setupOf($environment, $now, $shards);
        $stop = $shards->stop()->after(
            $setup instanceof Seconds ? $setup : $jobs->setupOr($shards->setup()),
            $jobs->windDownOr(Seconds::of(JobStop::WIND_DOWN_SECONDS)),
        );
        $budget = $settings->triage()->budget();
        $sooner = $budget instanceof Seconds && $stop->outlasts($budget) ? $budget : $stop;

        return new self(Deadline::after($now, $sooner), $setup);
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
