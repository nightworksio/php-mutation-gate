<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestRow;

/** One test or data set row, by its runner's name, and what the mutants it judged say of it. */
final readonly class TestStanding
{
    private function __construct(
        private TestName|TestRow|TestId $name,
        private int $judged,
        private int $kills,
        private int $beaten,
    ) {
    }

    /**
     * A test that judged these many mutants with a known result, killed
     * these many first, and ran behind another test's first kill in these many.
     */
    public static function of(TestName|TestRow|TestId $name, int $judged, int $kills, int $beaten): self
    {
        return new self($name, $judged, $kills, $beaten);
    }

    public function name(): TestName|TestRow|TestId
    {
        return $this->name;
    }

    /** How many mutants it judged with a known result. */
    public function judged(): int
    {
        return $this->judged;
    }

    public function standing(): Standing
    {
        return match (true) {
            $this->judged === 0 => Standing::NotAssessed,
            $this->kills > 0 => Standing::Useful,
            $this->beaten > 0 => Standing::NeverFirst,
            default => Standing::KillsNothing,
        };
    }
}
