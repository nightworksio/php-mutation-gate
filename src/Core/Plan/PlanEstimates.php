<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;
use function implode;
use function max;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * What a plan expects its run to take (ADR-0006, decision 4): each shard's
 * units, opening run and `shards.setup`, and what the estimate rests on,
 * learned from earlier shards, measured from the plan's first run, or
 * guessed from `costs.secondsPerLine`. A shard of no units is no job.
 */
final readonly class PlanEstimates
{
    private const string SHARD = 'Shard %d (%s): about %s, %s.';

    private const string TOTAL
        = 'The plan expects about %s of wall time and %s of runner time: %d%% learned, %d%% measured, %d%% guessed.';

    private const string ASSUMED = 'It takes each shard\'s runner to run %d mutants at once, as this machine does.';

    private const string UNMET = <<<'SAID'
        shards.target is %s, and at shards.max of %d shards the longest is expected to take %s.
        Raise shards.max, or shards.target, to meet it.
        SAID;

    private const string UNSPLIT = <<<'SAID'
        shards.target is %s, and at %d shards the longest is expected to take %s.
        More shards would each cost less than twice their opening run and setup, or split a unit, which no cut does.
        Raise shards.target to meet it.
        SAID;

    private function __construct(private Plan $plan, private Seconds $setup)
    {
    }

    /** The estimates of a plan whose every job first spends this long on its setup. */
    public static function of(Plan $plan, Seconds $setup): self
    {
        return new self($plan, $setup);
    }

    /** The wall time of the longest shard, and the runner time of every shard together. */
    public function runTime(): RunTime
    {
        $wall = 0.0;
        $runner = 0.0;

        foreach ($this->plan as $shard) {
            $job = $this->jobOf($shard);
            $wall = max($wall, $job);
            $runner += $job;
        }

        return RunTime::estimated(Seconds::of($wall), Seconds::of($runner));
    }

    /** The share of the units' time that rests on this basis; none of a plan with no units' time. */
    public function share(CostBasis $basis): Percentage
    {
        $part = 0;
        $whole = 0;

        foreach ($this->plan as $shard) {
            $part += $shard->estimate()->part($basis)->microseconds();
            $whole += $shard->cost()->microseconds();
        }

        $share = $whole > 0 ? Percentage::share($part, $whole) : Percentage::none();

        return $share instanceof Percentage ? $share : Percentage::none();
    }

    /**
     * What the plan sets the run to do, with the share of its estimate that
     * rests on more than a guess and the changed lines no test runs.
     */
    public function work(): PlannedWork
    {
        $guessed = $this->share(CostBasis::Guessed);
        $measured = Percentage::inHundredths(Percentage::whole()->hundredths() - $guessed->hundredths());

        $work = PlannedWork::of(
            $this->plan,
            $this->runTime(),
            $measured instanceof Percentage ? $measured : Percentage::none(),
        );

        foreach ($this->plan->considered()->untested() as $change) {
            $work = $work->withUncovered($change->path(), $change->lines());
        }

        return $work;
    }

    /**
     * One line for each shard with units: what it is expected to take, and
     * what the estimate rests on.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->plan as $shard) {
            if (! $shard->isEmpty()) {
                $lines[] = sprintf(
                    self::SHARD,
                    $shard->id()->number(),
                    $shard->label(),
                    Seconds::of($this->jobOf($shard))->text(),
                    $this->basisOf($shard),
                );
            }
        }

        return $lines;
    }

    /**
     * What the run is expected to take, in wall time and runner time, and
     * what that rests on; where it rests on a measured first run, how many
     * mutants each shard's runner is taken to run at once; and where
     * `shards.target` cannot be met under `shards.max`, a line that says so.
     *
     * @return list<string>
     */
    public function summary(ProcessCount $processes, Seconds|Absent $target, int $most): array
    {
        $lines = [$this->total(), ...$this->assumed($processes)];

        foreach ($this->unmet($target, $most) as $warning) {
            $lines[] = $warning->text();
        }

        return $lines;
    }

    /** What the run is expected to take, in wall time and runner time, and what that rests on. */
    private function total(): string
    {
        $run = $this->runTime();

        return sprintf(
            self::TOTAL,
            $run->wall()->text(),
            $run->runner()->text(),
            $this->share(CostBasis::Learned)->wholePercent(),
            $this->share(CostBasis::Measured)->wholePercent(),
            $this->share(CostBasis::Guessed)->wholePercent(),
        );
    }

    /**
     * Where any unit's estimate rests on what the plan measured of its first
     * run, a line saying the runners the shards run on are taken to run as
     * many mutants at once as the machine the plan ran on (ADR-0006,
     * decision 4).
     *
     * @return list<string>
     */
    private function assumed(ProcessCount $processes): array
    {
        $measured = 0;

        foreach ($this->plan as $shard) {
            $measured += $shard->estimate()->part(CostBasis::Measured)->microseconds();
        }

        return $measured > 0 ? [sprintf(self::ASSUMED, $processes->count())] : [];
    }

    /**
     * Where `shards.target` asks for a wall time the longest shard cannot
     * meet, a warning that says what stops it: `shards.max` (ADR-0013,
     * decision 6), or, short of it, shards that would each cost less than
     * twice their overhead or a unit no cut splits (decision 7); none where
     * it is met or there is no target.
     */
    private function unmet(Seconds|Absent $target, int $most): Warnings
    {
        $longest = $this->runTime()->wall();

        if (! $target instanceof Seconds || $longest->seconds() <= $target->seconds()) {
            return Warnings::none();
        }

        $shards = count($this->plan);

        return Warnings::of(Warning::that($shards >= $most
            ? sprintf(self::UNMET, $target->text(), $most, $longest->text())
            : sprintf(self::UNSPLIT, $target->text(), $shards, $longest->text())));
    }

    /** A shard's job: its units, its opening run and its setup; no time for a shard of no units. */
    private function jobOf(Shard $shard): float
    {
        return $shard->isEmpty() ? 0.0 : $shard->cost()->seconds()
            + $shard->estimate()->openingRun()->seconds()
            + $this->setup->seconds();
    }

    /** What a shard's estimate rests on, as the line names it. */
    private function basisOf(Shard $shard): string
    {
        $bases = [];

        foreach (CostBasis::cases() as $basis) {
            $bases = $shard->estimate()->part($basis)->seconds() > 0.0 ? [...$bases, $basis->value] : $bases;
        }

        return $bases === [] ? CostBasis::Guessed->value : implode(' and ', $bases);
    }
}
