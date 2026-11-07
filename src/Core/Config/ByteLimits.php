<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * How many bytes of a file may be read, compressed and decompressed, as a `proofs.limits` entry writes them.
 *
 * @internal
 */
final readonly class ByteLimits
{
    private function __construct(private int|Absent $packed, private int|Absent $unpacked)
    {
    }

    public static function of(int|Absent $packed, int|Absent $unpacked): self
    {
        return new self($packed, $unpacked);
    }

    public function packed(): int|Absent
    {
        return $this->packed;
    }

    public function unpacked(): int|Absent
    {
        return $this->unpacked;
    }
}
