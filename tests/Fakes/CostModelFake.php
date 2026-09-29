<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_sum;
use function max;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Port\CostModel;

/**
 * A cost model that trusts what a shard measured and guesses one flat figure
 * otherwise, and shares a shard's time among its units by their mutants.
 */
final readonly class CostModelFake implements CostModel
{
    public function __construct(private Seconds $guess)
    {
    }

    public function cost(Unit $unit, Timings $learned): Seconds
    {
        $measured = $learned->secondsFor($unit->path());

        return $measured instanceof Seconds ? $measured : $this->guess;
    }

    public function learn(Units $units, Mutants $mutants, Seconds $spent): Timings
    {
        $counts = [];

        foreach ($units as $unit) {
            $counts[$unit->path()->value()] = 0;

            foreach ($mutants as $mutant) {
                $counts[$unit->path()->value()] += $mutant->location()->file()->equals($unit->path()) ? 1 : 0;
            }
        }

        $total = max(1, array_sum($counts));
        $timings = Timings::none();

        foreach ($units as $unit) {
            $timings = $timings->with(Timing::of($unit->path(), Seconds::of($spent->seconds() * $counts[$unit->path()->value()] / $total)));
        }

        return $timings;
    }
}
