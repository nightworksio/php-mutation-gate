<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;

use function sprintf;

/**
 * A shard whose units `paths` entries group, run under `runner.pathRules: partitioned` (ADR-0074): each group
 * one after another, the units no entry matches with the run's own adapters and settings, and each other group
 * with its entries' settings and the runner, the analyser and the mutators they build, the outcomes joined.
 * Once a group dooms the run, the groups after it are left unjudged; the first that cannot judge stops it.
 *
 * @internal
 */
final readonly class PartitionedRuns
{
    private const string UNRULED = 'A group of units paths entries match cannot be run: %s';

    private const string NO_GROUP = 'The shard held no group of units to run.';

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private Ruling $ruling,
        private DateTimeImmutable $now,
    ) {
    }

    /** @param Closure(Adapters, Settings, Shard): (Mutated|CannotJudge) $run how one group's shard runs */
    public function mutated(PathGroups $groups, Closure $run): Mutated|CannotJudge
    {
        $outcome = NotGiven::value();

        foreach ($groups as $index => $group) {
            if ($outcome instanceof Mutated && $outcome->doomed instanceof Doomed) {
                return $outcome->leaving($groups->unitsFrom($index));
            }

            $after = $this->after($outcome, $this->ran($group, $run));

            if ($after instanceof CannotJudge) {
                return $after;
            }

            $outcome = $after;
        }

        return $outcome instanceof Mutated ? $outcome : CannotJudge::because(self::NO_GROUP);
    }

    /** What the groups came to, with one more group's outcome after it; or why that group cannot judge. */
    private function after(Mutated|NotGiven $outcome, Mutated|CannotJudge $mutated): Mutated|CannotJudge
    {
        return $outcome instanceof Mutated && $mutated instanceof Mutated ? $outcome->and($mutated) : $mutated;
    }

    /** @param Closure(Adapters, Settings, Shard): (Mutated|CannotJudge) $run */
    private function ran(PathGroup $group, Closure $run): Mutated|CannotJudge
    {
        $rules = $group->rules();

        if ($rules->isNone()) {
            return $run($this->adapters, $this->settings, $group->shard());
        }

        $settings = Settings::settled($this->settings->effective()->over($rules->layer()), $this->now);

        if ($settings instanceof Invalid) {
            return CannotJudge::because(sprintf(self::UNRULED, $settings->said()));
        }

        $adapters = $this->ruling->adapters($this->adapters, $settings);

        return match (true) {
            $adapters instanceof Adapters => $run($adapters, $settings, $group->shard()),
            $adapters instanceof Invalid => CannotJudge::because(sprintf(self::UNRULED, $adapters->said())),
            default => $adapters,
        };
    }
}
