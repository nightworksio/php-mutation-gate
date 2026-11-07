<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function min;

use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Config\TestsTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\KillsLearned;
use NightWorksIO\MutationGate\Core\Proof\LimitHistory;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;

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
 * shard weighs a check before a mutant's tests by (ADR-0107).
 *
 * @internal
 */
final readonly class LearnedLimits
{
    private const string MULTIPLE
        = 'timeouts.multiple learned: %s, what 99 in 100 of %d kills took over their tests\' time, in place of %d.';

    private const string MULTIPLE_COLD = 'timeouts.multiple learned: %d, the fixed one: the ledger holds no kill yet.';

    private const string SHARDS = 'shards.max learned: %d, %s the last %d runs ran at once, in place of %d.';

    private const string SHARDS_COLD = 'shards.max learned: %d, the fixed one: the ledger holds no sharded run yet.';

    private const string FLOOR
        = 'timeouts.seconds learned: %d times each shard\'s start-up, at least 1 s; %d s where none is measured.';

    private const string PACE
        = 'staticCheck.testsTime learned: %s times their tests\' own time, the median of %d kills.';

    private const string MOST_AT_ONCE = 'the most shards';

    private const string MEDIAN_AT_ONCE = 'the median of the shards';

    /** @param list<string> $lines */
    private function __construct(
        private Multiple|NotGiven $multiple,
        private int|NotGiven $shards,
        private Multiple|NotGiven $pace,
        private array $lines,
    ) {
    }

    public static function of(Settings $settings, Ledgers $ledgers): self
    {
        $bounds = $settings->triage()->bounds();
        $kills = $ledgers->own()->learned()->limits()->and($ledgers->defaultBranch()->learned()->limits());
        $runs = $ledgers->defaultBranch()->learned()->limits();
        $statistic = $settings->shards()->learnedFrom();
        $multiple = $bounds->learnsMultiple() ? self::multipleOf($kills) : NotGiven::value();
        $shards = $statistic instanceof LearnedStatistic ? self::shardsOf($runs, $statistic) : NotGiven::value();
        $floor = $settings->triage()->limit()->seconds();
        $check = $settings->staticCheck();
        $pacing = $check->before()
            && $check->tool()->use()->value() !== StaticCheck::NONE
            && $check->testsTime() === TestsTime::Learned;
        $pace = $pacing ? $kills->pace() : NotGiven::value();
        $paceSaid = $pace instanceof Multiple
            ? [sprintf(self::PACE, round($pace->times(), 2), $kills->killCount())]
            : [];

        return new self($multiple, $shards, $pace, [
            ...$bounds->learnsFloor()
                ? [sprintf(self::FLOOR, MutantLimit::FACTOR, $floor)]
                : [],
            ...$bounds->learnsMultiple() ? [self::multipleSaid($kills)] : [],
            ...$statistic instanceof LearnedStatistic ? [self::shardsSaid($runs, $statistic)] : [],
            ...$paceSaid,
        ]);
    }

    /** The briefing, telling every shard the multiple and the pace learned, where each was. */
    public function briefing(Briefing $briefing): Briefing
    {
        $none = KillsLearned::none();
        $kills = $this->multiple instanceof NotGiven ? $none : $none->allowing($this->multiple);

        return $briefing->learning($this->pace instanceof NotGiven ? $kills : $kills->pacing($this->pace));
    }

    /** The cut, at most the shards learned, where they were. */
    public function cut(Cut $cut): Cut
    {
        return $this->shards instanceof NotGiven ? $cut : $cut->atMost($this->shards);
    }

    /** @return list<string> a line for each learned limit, saying what it is and why */
    public function lines(): array
    {
        return $this->lines;
    }

    private static function multipleOf(LimitHistory $kills): Multiple
    {
        $learned = $kills->multiple();

        return $learned instanceof NotGiven ? Multiple::standard() : $learned;
    }

    private static function shardsOf(LimitHistory $runs, LearnedStatistic $statistic): int
    {
        $learned = $runs->shards($statistic);
        $most = Shards::standard()->max();

        return $learned instanceof NotGiven ? $most : min($learned, $most);
    }

    private static function multipleSaid(LimitHistory $kills): string
    {
        $learned = $kills->multiple();

        return $learned instanceof NotGiven
            ? sprintf(self::MULTIPLE_COLD, MutantLimit::FACTOR)
            : sprintf(self::MULTIPLE, round($learned->times(), 2), $kills->killCount(), MutantLimit::FACTOR);
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
