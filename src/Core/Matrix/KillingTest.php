<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A whole test, the mutants its rows killed, and the time its rows ran for under coverage. */
final readonly class KillingTest
{
    private function __construct(
        private TestName|TestId $test,
        private MutantIds $kills,
        private Seconds|Unmeasured $seconds,
    ) {
    }

    /** A test that has killed nothing yet, and was never timed. */
    public static function of(TestName|TestId $test): self
    {
        return new self($test, MutantIds::none(), Unmeasured::duration());
    }

    /** This test, with one more row: the mutants it killed, and how long it ran. */
    public function withRow(MutantIds $kills, Seconds|Unmeasured $seconds): self
    {
        $total = match (true) {
            ! $seconds instanceof Seconds => $this->seconds,
            $this->seconds instanceof Seconds => Seconds::of($this->seconds->seconds() + $seconds->seconds()),
            default => $seconds,
        };

        return new self($this->test, $this->kills->and($kills), $total);
    }

    public function test(): TestName|TestId
    {
        return $this->test;
    }

    public function kills(): MutantIds
    {
        return $this->kills;
    }

    public function seconds(): Seconds|Unmeasured
    {
        return $this->seconds;
    }

    /** Its time; this one where no row of it was timed. */
    public function secondsOr(Seconds $untimed): Seconds
    {
        return $this->seconds instanceof Seconds ? $this->seconds : $untimed;
    }
}
