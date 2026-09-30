<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Path;

/** A unit no proof in the ledgers read is of, whatever its key. */
final readonly class NeverProved
{
    private function __construct(private Path $unit)
    {
    }

    public static function unit(Path $unit): self
    {
        return new self($unit);
    }

    public function path(): Path
    {
        return $this->unit;
    }
}
