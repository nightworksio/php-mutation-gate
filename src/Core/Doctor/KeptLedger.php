<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\File\Path;

/** A ledger the proof store keeps, as `doctor` weighs it: its file, and its size compressed, as it is kept. */
final readonly class KeptLedger
{
    private function __construct(private Path $file, private int $bytes)
    {
    }

    public static function of(Path $file, int $bytes): self
    {
        return new self($file, $bytes);
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }
}
