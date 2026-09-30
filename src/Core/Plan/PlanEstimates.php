<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;
use function implode;
use function max;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * What a plan expects its run to take (ADR-0006, decision 3): each shard's
 * units, opening run and `shards.setup`, and what the estimate rests on,
 * learned from earlier shards, measured by the plan's own coverage run, or
 * guessed from `costs.secondsPerLine`. A shard of no units is no job.
 */
final readonly class PlanEstimates
{
    private const string SHARD = 'Shard %d (%s): about %s, %s.';

    private const string TOTAL
        = 'The plan expects about %s of wall time and %s of runner time: %d%% learned, %d%% measured, %d%% guessed.';

    private const string UNMET = <<<'SAID'
        shards.target is %s, and at shards.max of %d shards the longest is expected to take %s.
        Raise shards.max, or shards.target, to meet it.
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

    /** What the plan sets the run to do, with the share of its estimate that rests on more than a guess. */
    public function work(): PlannedWork
    {
        $guessed = $this->share(CostBasis::Guessed);
        $measured = Percentage::inHundredths(Percentage::whole()->hundredths() - $guessed->hundredths());

        return PlannedWork::of(
            $this->plan,
            $this->runTime(),
            $measured instanceof Percentage ? $measured : Percentage::none(),
        );
    }

    /**
     * One line for each shard with units, and one for the run: what each is
     * expected to take, and what the estimate rests on.
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

        $run = $this->runTime();

        return [...$lines, sprintf(
            self::TOTAL,
            $run->wall()->text(),
            $run->runner()->text(),
            $this->share(CostBasis::Learned)->wholePercent(),
            $this->share(CostBasis::Measured)->wholePercent(),
            $this->share(CostBasis::Guessed)->wholePercent(),
        )];
    }

    /**
     * Where `shards.target` asks for a wall time the longest shard cannot
     * meet, because `shards.max` stops the cut short (ADR-0013, decision 6):
     * a warning that says so; none where it is met or there is no target.
     */
    public function unmet(Seconds|Absent $target, int $most): Warnings
    {
        $longest = $this->runTime()->wall();
        $missed = $target instanceof Seconds && $longest->seconds() > $target->seconds();

        return $missed && count($this->plan) >= $most
            ? Warnings::of(Warning::that(sprintf(self::UNMET, $target->text(), $most, $longest->text())))
            : Warnings::none();
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
