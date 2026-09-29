<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;

/** A content key no proof is stored under, so its unit runs. */
final readonly class Unproved
{
    private function __construct(private Digest $key)
    {
    }

    public static function key(Digest $key): self
    {
        return new self($key);
    }

    public function digest(): Digest
    {
        return $this->key;
    }
}
