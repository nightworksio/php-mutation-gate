<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * A killed mutant as a ledger proved it: its id, the line it starts on in
 * its unit, its mutator and the tests that killed it. A ledger keeps no more
 * of a killed mutant, so it has no native id, family, diff or duration; what
 * reads one says what it needs of those, rather than read a placeholder.
 */
final readonly class ProvedKill
{
    private function __construct(
        private MutantId $id,
        private Location $location,
        private string $mutator,
        private TestIds $killers,
    ) {
    }

    public static function of(MutantId $id, Path $unit, Line $line, string $mutator, TestIds $killers): self
    {
        return new self($id, Location::of($unit, $line, Unreported::line()), $mutator, $killers);
    }

    public function id(): MutantId
    {
        return $this->id;
    }

    /** Its unit and the line it starts on; the ledger keeps no end. */
    public function location(): Location
    {
        return $this->location;
    }

    /** The full name of its mutator, as the runner that killed it named it. */
    public function mutator(): string
    {
        return $this->mutator;
    }

    public function status(): MutantStatus
    {
        return MutantStatus::Killed;
    }

    /** The tests that killed it; none where no test is known to have, as for a kill by a timeout. */
    public function killers(): TestIds
    {
        return $this->killers;
    }

    /** A kill needs no reason, and the ledger keeps none. */
    public function reason(): Unreported
    {
        return Unreported::reason();
    }

    /** The ledger keeps no duration of a kill. */
    public function duration(): Unmeasured
    {
        return Unmeasured::duration();
    }

    /** The ledger keeps no limit of a kill. */
    public function limit(): Unmeasured
    {
        return Unmeasured::duration();
    }
}
