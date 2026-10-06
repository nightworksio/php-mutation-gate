<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\File\Missing;

/** The coverage map a store keeps for a run, or why there is none to read, and the scope it was read from. */
final readonly class StoredCoverage
{
    private function __construct(private KeptMap|Missing|CannotJudge $map, private KeptFrom $from)
    {
    }

    public static function of(KeptMap|Missing|CannotJudge $map, KeptFrom $from): self
    {
        return new self($map, $from);
    }

    public function map(): KeptMap|Missing|CannotJudge
    {
        return $this->map;
    }

    public function from(): KeptFrom
    {
        return $this->from;
    }
}
