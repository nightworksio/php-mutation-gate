<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\StaticChecker;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * The static analyser's one run over the originals, made once as the plan is
 * made where its shards check mutants with it (ADR-0164): its cache, where
 * the analyser keeps one, every shard's warm-up starts from, and how long it
 * took, by which analyser, which each shard takes for a check's time until
 * one is measured, as its own warm-up from that cache takes less.
 *
 * @internal
 */
final readonly class PlannedWarmUp
{
    /** Where the plan leaves how long the warm-up took, beside the plan. */
    private const string FILE = '%s/warm-up.json';

    private const string ANALYSER = 'analyser';

    private const string VERSION = 'version';

    private const string CONFIG = 'config';

    private const string SECONDS = 'seconds';

    public function __construct(private Adapters $adapters, private Settings $settings, private ClockInterface $clock)
    {
    }

    /**
     * How long the warm-up the plan made took, where it made one by this
     * analyser, as it is configured; none otherwise.
     */
    public static function secondsFor(Directory $project, AnalyserIdentity $identity): Seconds|NotGiven
    {
        $contents = $project->read(self::file());

        if (! $contents instanceof Contents) {
            return NotGiven::value();
        }

        try {
            $read = Node::decode($contents->text());
            $same = $read->field(self::ANALYSER)->text() === $identity->analyser()
                && $read->field(self::VERSION)->text() === $identity->version()
                && $read->field(self::CONFIG)->text() === $identity->config()->value();
            $seconds = $read->field(self::SECONDS)->number();
        } catch (NotInShape) {
            return NotGiven::value();
        }

        return $same ? Seconds::of($seconds) : NotGiven::value();
    }

    /**
     * The analyser warmed up over the originals once, and how long it took
     * left beside the plan, where its shards check mutants with it and
     * `staticCheck.warmAtPlan` is on; none left of an earlier plan's
     * otherwise, or where the warm-up fails, so each shard warms up itself.
     */
    public function left(Plan $plan): Written|Missing|CannotJudge
    {
        $identity = $this->adapters->analyser;

        return $identity instanceof AnalyserIdentity
            ? $this->leftBy($identity, $this->warmed($plan))
            : $this->adapters->project->remove(self::file());
    }

    /** How long this analyser's warm-up took, left beside the plan; none where it made none. */
    private function leftBy(AnalyserIdentity $identity, Seconds|NotGiven $took): Written|Missing|CannotJudge
    {
        return $took instanceof Seconds
            ? $this->adapters->project->write(self::file(), self::written($identity, $took))
            : $this->adapters->project->remove(self::file());
    }

    /** How long a warm-up by this analyser took, as the plan leaves it. */
    private static function written(AnalyserIdentity $identity, Seconds $took): Contents
    {
        return Contents::of(Json::object(
            Member::of(self::ANALYSER, $identity->analyser()),
            Member::of(self::VERSION, $identity->version()),
            Member::of(self::CONFIG, $identity->config()->value()),
            Member::of(self::SECONDS, $took->seconds()),
        )->printed());
    }

    /** How long the warm-up took, where the plan makes one; none where it makes none, or it fails. */
    private function warmed(Plan $plan): Seconds|NotGiven
    {
        $checker = $this->adapters->checker;
        $checks = $this->settings->staticCheck()->before() || $this->settings->toggles()->on(Toggle::StaticCheckAfter);

        if (
            ! $checker instanceof StaticChecker
            || ! $checks
            || count($plan) === 0
            || ! $this->settings->toggles()->on(Toggle::StaticCheckWarmAtPlan)
        ) {
            return NotGiven::value();
        }

        $started = $this->clock->now();
        $found = $checker->findings(Paths::none(), $this->adapters->withheld);

        return $found instanceof CannotJudge ? NotGiven::value() : Seconds::between($started, $this->clock->now());
    }

    private static function file(): Path
    {
        return Path::of(sprintf(self::FILE, GateDirectory::root()->value()));
    }
}
