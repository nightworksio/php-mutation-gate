<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function is_float;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\LearnedBounds;
use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Config\TestsTime;
use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Plan\TailRule;
use NightWorksIO\MutationGate\Core\Proof\LimitHistory;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function round;
use function sprintf;

/**
 * The limits a config has the gate learn, as a plan learns them from the
 * ledgers it read (ADR-0073): `timeouts.multiple` from the kills
 * of its own scope's ledger, or else the default branch's, which every shard
 * allows; `shards.max` from the runs of the default branch's ledger alone,
 * between one and the standard most; and the lines that say what each is and
 * why, `timeouts.seconds` among them, which each shard learns from the
 * start-up it measures; and, where `staticCheck.testsTime` is learned and an
 * analyser checks before the tests, the median pace of those kills, which each
 * shard weighs a check before a mutant's tests by (ADR-0107); and, from the
 * jobs of its own scope's ledger, or else the default branch's, on the
 * platform the plan runs on, what jobs take there: the longest setup and
 * wind-down, which each job's stop and the cut's are read from, and the
 * median second pass's factor, which the second wave is priced by
 * (ADR-0196).
 *
 * @internal
 */
final readonly class LearnedLimits
{
    private const string MULTIPLE = <<<'SAID'
        timeouts.multiple learned: %s,
        rounded up from what %s in 100 of %d kills took over their tests' time, in place of %d.
        SAID;

    private const string MULTIPLE_COLD = 'timeouts.multiple learned: %d, the fixed one: the ledger holds no kill yet.';

    private const string SHARDS = 'shards.max learned: %d, %s the last %d runs ran at once, in place of %d.';

    private const string SHARDS_COLD = 'shards.max learned: %d, the fixed one: the ledger holds no sharded run yet.';

    private const string FLOOR
        = 'timeouts.seconds learned: %s times each shard\'s start-up, at least 1 s; %d s where none is measured.';

    private const string PACE
        = 'staticCheck.testsTime learned: %s times their tests\' own time, the median of %d kills.';

    private const string SETUP = 'shards.setup learned: %s s, the longest of the recent jobs on this platform.';

    private const string WIND_DOWN
        = 'the wind-down learned: %s s, the longest a recent gate went on for past its stop.';

    private const string PASS_TWO
        = 'the second pass learned: %s times its covering tests\' seconds, the median of recent second-wave jobs.';

    private const string MOST_AT_ONCE = 'the most shards';

    private const string MEDIAN_AT_ONCE = 'the median of the shards';

    /** @param list<string> $lines */
    private function __construct(
        private Multiple|NotGiven $multiple,
        private int|NotGiven $shards,
        private Multiple|NotGiven $pace,
        private array $lines,
        private JobsLearned $jobs,
        private Settings $settings,
    ) {
    }

    /** @param Identity|CannotJudge|NotGiven $runner the runner the plan runs, on the platform it runs on */
    public static function of(
        Settings $settings,
        Ledgers $ledgers,
        Identity|CannotJudge|NotGiven $runner = new NotGiven(),
    ): self {
        $bounds = $settings->triage()->limits()->bounds();
        $kills = $ledgers->own()->learned()->limits()->and($ledgers->defaultBranch()->learned()->limits());
        $runs = $ledgers->defaultBranch()->learned()->limits();
        $statistic = $settings->shards()->learnedFrom();
        $read = $settings->triage()->learned();
        $multiple = $bounds->learnsMultiple() ? self::multipleOf($kills, $read) : NotGiven::value();
        $shards = $statistic instanceof LearnedStatistic ? self::shardsOf($runs, $statistic) : NotGiven::value();
        $floor = $settings->triage()->limits()->limit()->seconds();
        $check = $settings->staticCheck();
        $pacing = $check->before()
            && $check->tool()->use()->value() !== StaticCheck::NONE
            && $check->testsTime() === TestsTime::Learned;
        $pace = $pacing ? $kills->pace() : NotGiven::value();
        $paceSaid = $pace instanceof Multiple
            ? [sprintf(self::PACE, round($pace->times(), 2), $kills->killCount())]
            : [];

        $jobs = self::jobsOf($kills, $runner);

        return new self($multiple, $shards, $pace, [
            ...$bounds->learnsFloor()
                ? [sprintf(self::FLOOR, round($bounds->startUp()->multiple(), 2), $floor)]
                : [],
            ...$bounds->learnsMultiple() ? [self::multipleSaid($kills, $read)] : [],
            ...$statistic instanceof LearnedStatistic ? [self::shardsSaid($runs, $statistic)] : [],
            ...$paceSaid,
            ...self::jobsSaid($jobs),
        ], $jobs, $settings);
    }

    /**
     * The briefing, telling every shard the multiple and the pace learned,
     * where each was, and how the plan's jobs stop.
     */
    public function briefing(Briefing $briefing, JobTail $tail): Briefing
    {
        $none = PlanLearned::none();
        $kills = $this->multiple instanceof NotGiven ? $none : $none->allowing($this->multiple);

        return $briefing->learning(
            ($this->pace instanceof NotGiven ? $kills : $kills->pacing($this->pace))
                ->timing($this->jobs)
                ->tailing($tail),
        );
    }

    /** The multiple learned, which every key holds; none where the config has none learned. */
    public function multiple(): Multiple|NotGiven
    {
        return $this->multiple;
    }

    /**
     * The cut, at most the shards learned, where they were, each within the
     * stop the jobs learned leave it, where they taught their setup or
     * wind-down.
     */
    public function cut(Cut $cut): Cut
    {
        $within = $this->jobs->teachesTheStop() ? $cut->withinJobLimit($this->stop()) : $cut;

        return $this->shards instanceof NotGiven ? $within : $within->atMost($this->shards);
    }

    /**
     * The cut, its jobs' tail decided by `shards.tail` under `costs.mode`,
     * the shards learned or `shards.max` running at once, each job of this
     * limit (ADR-0303).
     */
    public function tailing(Cut $cut, Seconds $limit): Cut
    {
        $shards = $this->settings->shards();
        $jobs = JobTail::of($limit, $this->shards instanceof NotGiven ? $shards->max() : $this->shards);
        $learned = PlanLearned::none()->timing($this->jobs)->tailing($jobs->running());

        return $cut->tailing(TailRule::of(
            $shards->tail(),
            $shards->costs()->mode(),
            $jobs,
            $learned->stop($shards),
        ));
    }

    /** How long a job's gate runs before it stops at its target, as the jobs learned leave it. */
    public function stop(): Seconds
    {
        return PlanLearned::none()->timing($this->jobs)->stop($this->settings->shards());
    }

    /** What the plan learned of the jobs on its platform. */
    public function jobs(): JobsLearned
    {
        return $this->jobs;
    }

    /** @return list<string> a line for each learned limit, saying what it is and why */
    public function lines(): array
    {
        return $this->lines;
    }

    /** What the jobs on the runner's platform taught; nothing where the runner cannot say what it runs on. */
    private static function jobsOf(LimitHistory $history, Identity|CannotJudge|NotGiven $runner): JobsLearned
    {
        return $runner instanceof Identity ? $history->jobsOn($runner->platform()) : JobsLearned::none();
    }

    /** @return list<string> a line for each of what the jobs taught, where they taught it */
    private static function jobsSaid(JobsLearned $jobs): array
    {
        $setup = $jobs->setup();
        $windDown = $jobs->windDown();
        $passTwo = $jobs->passTwo();

        return [
            ...$setup instanceof Seconds ? [sprintf(self::SETUP, round($setup->seconds(), 1))] : [],
            ...$windDown instanceof Seconds ? [sprintf(self::WIND_DOWN, round($windDown->seconds(), 1))] : [],
            ...is_float($passTwo) ? [sprintf(self::PASS_TWO, round($passTwo, 2))] : [],
        ];
    }

    private static function multipleOf(LimitHistory $kills, LearnedBounds $bounds): Multiple
    {
        $learned = $kills->multiple($bounds);

        return $learned instanceof NotGiven ? Multiple::standard() : $learned;
    }

    private static function shardsOf(LimitHistory $runs, LearnedStatistic $statistic): int
    {
        $learned = $runs->shards($statistic);
        $most = Shards::standard()->max();

        return $learned instanceof NotGiven ? $most : min($learned, $most);
    }

    private static function multipleSaid(LimitHistory $kills, LearnedBounds $bounds): string
    {
        $learned = $kills->multiple($bounds);

        return $learned instanceof NotGiven
            ? sprintf(self::MULTIPLE_COLD, MutantLimit::FACTOR)
            : sprintf(
                self::MULTIPLE,
                round($learned->times(), 2),
                $bounds->inAHundred(),
                $kills->killCount(),
                MutantLimit::FACTOR,
            );
    }

    private static function shardsSaid(LimitHistory $runs, LearnedStatistic $statistic): string
    {
        $most = Shards::standard()->max();

        return $runs->shards($statistic) instanceof NotGiven
            ? sprintf(self::SHARDS_COLD, $most)
            : sprintf(
                self::SHARDS,
                self::shardsOf($runs, $statistic),
                $statistic === LearnedStatistic::Max ? self::MOST_AT_ONCE : self::MEDIAN_AT_ONCE,
                $runs->runCount(),
                $most,
            );
    }
}
