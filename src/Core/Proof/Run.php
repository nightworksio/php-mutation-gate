<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * The run that established a proof, as its CI names it, such as
 * `github:<run id>/<attempt>`, when it did, the base it keyed its units at:
 * the digest of everything every key of the run reads, and how much of the
 * kill matrix it recorded: first killers, unless it was asked for every one.
 */
final readonly class Run
{
    private function __construct(
        private string $id,
        private Instant $at,
        private Digest $base,
        private MatrixKind $matrix,
    ) {
    }

    public static function of(string $id, Instant $at, Digest $base): self
    {
        return new self($id, $at, $base, MatrixKind::FirstKiller);
    }

    /** This run, recording this much of the kill matrix. */
    public function recording(MatrixKind $matrix): self
    {
        return clone($this, ['matrix' => $matrix]);
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

    public function matrix(): MatrixKind
    {
        return $this->matrix;
    }
}
