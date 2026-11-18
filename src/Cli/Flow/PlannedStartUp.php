<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * How long a mutant's own run takes to start, measured once as the plan is
 * made (see StartUpTiming, ADR-0165): left beside the plan with the runner
 * and the platform it ran on, for every shard whose runner runs on the same
 * one to lay its limits on, measuring nothing itself.
 *
 * @internal
 */
final readonly class PlannedStartUp
{
    /** Where the plan leaves the start-up it measured, beside the plan. */
    private const string FILE = '%s/start-up.json';

    private const string RUNNER = 'runner';

    private const string PLATFORM = 'platform';

    private const string SECONDS = 'seconds';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** The start-up the plan measured, where it measured one for this runner on this platform; none otherwise. */
    public static function secondsFor(Directory $project, Identity $runner): Seconds|NotGiven
    {
        $contents = $project->read(self::file());

        if (! $contents instanceof Contents) {
            return NotGiven::value();
        }

        try {
            $read = Node::decode($contents->text());
            $same = $read->field(self::RUNNER)->text() === $runner->runner()
                && $read->field(self::PLATFORM)->text() === $runner->platform()->value();
            $seconds = $read->field(self::SECONDS)->number();
        } catch (NotInShape) {
            return NotGiven::value();
        }

        return $same ? Seconds::of($seconds) : NotGiven::value();
    }

    /**
     * The start-up of the plan's shards, measured once and left beside the
     * plan, under `timeouts.startUpAtPlan`; none left of an earlier plan's
     * otherwise, or where it cannot be measured, so each shard measures it.
     */
    public function left(Plan $plan, CoverageMap $map): Written|Missing|CannotJudge
    {
        $runner = $this->adapters->runner->identity($this->adapters->withheld);
        $measured = $runner instanceof Identity && $this->settings->toggles()->on(Toggle::TimeoutsStartUpAtPlan)
            ? new StartUpTiming($this->adapters, $this->settings->shards()->planning()->startUpSamples())
                ->of($map, $this->unitsOf($plan))
            : NotGiven::value();

        return $runner instanceof Identity && $measured instanceof Seconds
            ? $this->adapters->project->write(self::file(), $this->written($runner, $measured))
            : $this->adapters->project->remove(self::file());
    }

    private function unitsOf(Plan $plan): Units
    {
        $units = Units::none();

        foreach ($plan as $shard) {
            $units = $units->count() === 0 ? $shard->units() : $units;
        }

        return $units;
    }

    private function written(Identity $runner, Seconds $measured): Contents
    {
        return Contents::of(Json::object(
            Member::of(self::RUNNER, $runner->runner()),
            Member::of(self::PLATFORM, $runner->platform()->value()),
            Member::of(self::SECONDS, $measured->seconds()),
        )->printed());
    }

    private static function file(): Path
    {
        return Path::of(sprintf(self::FILE, GateDirectory::root()->value()));
    }
}
