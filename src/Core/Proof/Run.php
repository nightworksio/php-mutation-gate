<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * The run that established a proof, as its CI names it, such as
 * `github:<run id>/<attempt>`, when it did, and the base it keyed its units
 * at: the digest of everything every key of the run reads.
 */
final readonly class Run
{
    private function __construct(private string $id, private Instant $at, private Digest $base)
    {
    }

    public static function of(string $id, Instant $at, Digest $base): self
    {
        return new self($id, $at, $base);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function at(): Instant
    {
        return $this->at;
    }

    public function base(): Digest
    {
        return $this->base;
    }
}
